<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\Session;
use Runio\ScriptUi\Signal\Stop;

/**
 * Takes a request, decides whether it may come in, runs the tool and builds
 * the response. Everything that protects a tool happens here, before the tool
 * runs: the login check, the form token and the check of where the form came from.
 */
final class Kernel
{
    /** A saved form larger than this is not kept. */
    private const MAX_STATE_BYTES = 262144;

    /** A session remembers the inputs of this many tools. Beyond that the one that was used longest ago is forgotten. */
    private const MAX_STATES = 50;

    /** A message that waits in the session for the reloaded page is cut to this length. */
    private const MAX_MESSAGE_BYTES = 4096;

    /** Where uploaded files are kept. */
    private readonly UploadFolder $folder;

    /**
     * @param UploadFolder|null $folder where uploaded files are kept. null: the folder the settings name
     */
    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        ?UploadFolder $folder = null,
    ) {
        $this->folder = $folder ?? new UploadFolder($config->uploads);
    }

    /**
     * Runs one tool given as a callable.
     *
     * @param string|null $selfUrl  the address of this tool, where its form posts to; the request path by default
     * @param string|null $homeUrl  the address of the list of tools, if there is one
     * @param string|null $stateKey what the tool saves is filed under this; the tool id by default
     */
    public function handle(
        Request $request,
        string $toolId,
        callable $tool,
        ?string $selfUrl = null,
        ?string $homeUrl = null,
        ?string $stateKey = null,
    ): Response {
        $page = $this->begin($request, $toolId, $selfUrl, $homeUrl, $stateKey);
        if ($page instanceof Response) {
            return $page;
        }

        $this->execute($page, $tool);

        return $this->finish($page);
    }

    /**
     * Serves a folder of tool files: the list of tools, or the tool the address names.
     *
     * @param string|null $base null: tools are chosen with "?_tool=id" on the current address.
     *                          A path such as "" or "/admin/tools": tools live at "{base}/{id}".
     */
    public function serve(Request $request, ToolFinder $finder, ?string $base = null): Response
    {
        // First of all: who is asking. Nobody learns which tools exist before that.
        $user = $this->gate($request);
        if ($user instanceof Response) {
            return $user;
        }

        $route = self::route($request, $base);
        if ($route === null) {
            return $this->message(404, 'error.not_found', 'error.no_address');
        }

        if ($route['id'] === null) {
            $nonce = self::nonce();

            return new Response(200, Renderer::index(
                $finder->all(),
                $route['url'],
                $this->config,
                $user['name'],
                $finder->directory,
                $nonce,
            ), self::headers($nonce));
        }

        $tool = $finder->find($route['id']);
        if ($tool === null) {
            return $this->message(404, 'error.not_found', 'error.no_tool', [$route['home'], 'page.all_tools']);
        }

        $page = $this->open($request, $tool->id, $route['url']($tool->id), $route['home'], $user['name'], $tool->id);
        if ($page instanceof Response) {
            return $page;
        }

        $this->execute($page, static function () use ($tool): void {
            self::load($tool->file);
        });

        return $this->finish($page);
    }

    /**
     * Checks the request and prepares the page. Returns a response instead when
     * the request is refused or already answered (a redirect).
     */
    public function begin(
        Request $request,
        string $toolId,
        ?string $selfUrl = null,
        ?string $homeUrl = null,
        ?string $stateKey = null,
    ): Page|Response {
        $user = $this->gate($request);
        if ($user instanceof Response) {
            return $user;
        }

        return $this->open($request, $toolId, $selfUrl ?? $request->path, $homeUrl, $user['name'], $stateKey ?? $toolId);
    }

    /** Runs the tool on the page. Whatever the tool does, the page can be finished afterwards. */
    public function execute(Page $page, callable $tool): void
    {
        $previous = Runtime::enter($page, $this);
        $restore = self::quiet($page->debug);
        $page->capture();

        try {
            $tool();
        } catch (Stop) {
            // stop() and rerun(): the run ends here, on purpose.
        } catch (\Throwable $error) {
            $page->fail($error);
        } finally {
            $page->release();
            $restore();
            Runtime::leave($previous);
        }
    }

    /**
     * Decides what happens to the warnings and notices PHP prints while a tool runs.
     * They name files and lines, so without debug they only go to the error log.
     * With debug they are shown where they happened, as plain text.
     *
     * @return \Closure():void puts PHP's settings back
     */
    public static function quiet(bool $debug): \Closure
    {
        if (!function_exists('ini_set')) {
            return static function (): void {
            };
        }

        $before = ['html_errors' => ini_set('html_errors', '0')];
        if (!$debug) {
            $before['display_errors'] = ini_set('display_errors', '0');
        }

        return static function () use ($before): void {
            foreach ($before as $name => $value) {
                if ($value !== false) {
                    ini_set($name, $value);
                }
            }
        };
    }

    /** Saves what must be kept and turns the page into a response. */
    public function finish(Page $page): Response
    {
        $failure = $page->failure();

        if ($failure !== null && !$page->debug) {
            error_log(sprintf('Script UI: the tool "%s" failed: %s', $page->toolId, $failure));
        }

        if ($page->isPost) {
            // A run that failed half way must not overwrite what was saved before,
            // and it deletes no file either. Nor does a run that the page started by
            // itself: two windows of one tool would overwrite each other all day long,
            // and a file would be gone without the user having done anything.
            if ($failure === null && !$page->refreshing) {
                $this->saveState($page);
                $page->settleFiles();
            }
            if (!$this->audit($page) && $page->fileToSend() !== null) {
                // Who took which data out is exactly what a log is for. When the entry
                // cannot be written, the file is not handed out.
                $page->withhold(new \RuntimeException('The download could not be written to the log, so the file was not sent.'));
                $failure = $page->failure();
            }
        }

        if ($page->tookOver()) {
            // The tool sent an answer of its own, a file for example. Nothing is added to it.
            return new Response(alreadySent: true);
        }

        $file = $page->fileToSend();
        if ($file !== null && $failure === null) {
            // Saved, never shown: the type is never one a browser runs, nothing may guess
            // another type, and should it be opened in the browser after all, it may do nothing.
            return new Response(200, $file->body, [
                'Content-Type' => $file->type(),
                'Content-Disposition' => $file->disposition(),
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
                // The script of the page saves the file itself and reads the name here.
                // A header holds no Arabic, so the name is encoded.
                'X-Script-Ui-Download' => rawurlencode($file->name),
            ] + self::headers());
        }

        if ($page->wantsRerun() && $failure === null) {
            $messages = $page->messages();
            if ($messages !== []) {
                $flash = $this->session->get('flash');
                $flash = is_array($flash) ? $flash : [];
                $flash[$page->stateKey] = array_map(
                    static fn (array $message): array => [
                        'kind' => $message['kind'],
                        'text' => mb_strcut($message['text'], 0, self::MAX_MESSAGE_BYTES, 'UTF-8'),
                    ],
                    array_slice($messages, 0, 20),
                );
                $this->session->set('flash', $flash);
            }

            return new Response(303, '', self::headers() + ['Location' => $page->address()]);
        }

        $status = $failure === null ? 200 : 500;

        if ($page->fragment) {
            // The script of an open page asked. It only needs what goes inside the form.
            return new Response($status, $page->html(), self::headers() + [
                'X-Script-Ui-Fragment' => '1',
                'X-Script-Ui-Title' => rawurlencode(Renderer::title($page, $this->config)),
            ]);
        }

        $nonce = self::nonce();

        return new Response($status, Renderer::tool($page, $this->config, $this->token(), $nonce), self::headers($nonce));
    }

    /**
     * Who is asking, or the response that turns them away.
     *
     * @return array{name: ?string}|Response
     */
    private function gate(Request $request): array|Response
    {
        if ($this->config->auth === null) {
            if (Guard::isLocal($request)) {
                return ['name' => null];
            }

            return $this->message(403, 'error.local_only', 'error.local_only_text');
        }

        try {
            $result = self::call($this->config->auth, $request);
        } catch (\Throwable $error) {
            // Never show why to someone who is not logged in.
            error_log('Script UI: the login check failed: ' . $error);

            return $this->message(500, 'error.login_failed', 'error.login_failed_text');
        }

        if ($result === null || $result === false || $result === '' || $result === []) {
            return $this->message(403, 'error.denied', 'error.denied_text');
        }

        $name = match (true) {
            is_string($result) => $result,
            is_int($result), is_float($result) => (string) $result,
            $result instanceof \Stringable => (string) $result,
            default => null,
        };

        return ['name' => $name === null ? null : mb_substr(mb_scrub($name, 'UTF-8'), 0, 200, 'UTF-8')];
    }

    private function open(Request $request, string $toolId, string $selfUrl, ?string $homeUrl, ?string $user, string $stateKey): Page|Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'POST'], true)) {
            return $this->message(405, 'error.method', 'error.method_text', null, ['Allow' => 'GET, HEAD, POST']);
        }

        $token = $this->token();
        $isPost = $request->method === 'POST';

        if ($isPost && $request->post === [] && $request->files === []) {
            // PHP throws a request away that is larger than it takes: no field and no
            // file of it arrives, and the token is gone with them. Saying "the page has
            // expired" would send the user round in circles.
            $limit = $this->folder->bodyLimit();
            if ($limit !== null && $request->contentLength !== null && $request->contentLength > $limit) {
                return $this->message(413, 'error.too_large', 'error.too_large_text', [$selfUrl, 'error.too_large_link']);
            }
        }

        if ($isPost) {
            $sent = $request->post['_token'] ?? null;
            $crossSite = strtolower((string) $request->header('sec-fetch-site')) === 'cross-site';

            if (!is_string($sent) || !hash_equals($token, $sent) || $crossSite) {
                return $this->message(419, 'error.expired', 'error.expired_text', [$selfUrl, 'error.expired_link']);
            }
        }

        $states = $this->session->get('state');
        $states = is_array($states) ? $states : [];

        if (!$isPost && isset($request->query['_reset'])) {
            // Only the link on the page itself carries the right code. A link on
            // another site can bring the browser here, but it forgets nothing.
            $code = $request->query['_reset'];
            if (is_string($code) && hash_equals(self::resetCode($token, $stateKey), $code)) {
                unset($states[$stateKey]);
                $this->session->set('state', $states);
                $this->takeMessages($stateKey);
                (new Uploads($this->folder, $this->session, $stateKey))->forgetAll();
            }

            return new Response(303, '', self::headers() + ['Location' => $selfUrl]);
        }

        $saved = $states[$stateKey] ?? [];
        $saved = is_array($saved) && self::savedAt($saved, $selfUrl) ? $saved : [];
        // No run of the tool saved this, only a report of the page script. See noteLayout().
        $noRun = $saved === [] || isset($saved['_no_run']);
        unset($saved['_route'], $saved['_no_run']);

        if ($isPost && $request->header('x-script-ui') === 'layout') {
            return $this->noteLayout($states, $stateKey, $saved, $noRun, $request->post, $selfUrl);
        }

        // The page script asks, and only needs what goes inside the form. It says
        // "refresh" when it started the run by itself, because the tool called
        // refresh(). After rerun() it comes back with the same word, to read the page.
        $kind = $request->header('x-script-ui');
        $fragment = $kind === 'fragment' || $kind === 'refresh';

        // Nobody asked for this run. It brings no file and it presses nothing: no
        // button, no header of a table, no "remove" of a file. Whatever the request says.
        $refreshing = $isPost && $kind === 'refresh';

        $uploads = new Uploads($this->folder, $this->session, $stateKey, $isPost && !$refreshing ? $request->files : []);

        if ($isPost) {
            $in = $request->post;
            if ($refreshing) {
                unset($in['_click']);
            }
            $linked = [];
            $carried = [];
            // A file that arrives or is taken away is a changed input too: a table
            // that shows what is in the file starts again at its first page.
            // Without a run that kept its inputs there is nothing to compare the form with.
            $inputsChanged = (!$noRun && self::inputsChanged($saved, $in))
                || $uploads->brought()
                || (is_string($in['_click'] ?? null) && preg_match('/:remove:[0-9a-f]{32}$/D', $in['_click']) === 1);
        } else {
            [$in, $linked] = self::prefill($saved, $request->query);
            $carried = $this->takeMessages($stateKey);
            $inputsChanged = $linked !== [];
        }

        return new Page(
            $toolId,
            $isPost,
            $in,
            $user,
            // Error details by default only where nobody but the developer gets in:
            // no login check, and a request from this computer. With a login check
            // other people use the tools, and "looks local" proves too little.
            $this->config->debug ?? ($this->config->auth === null && Guard::isLocal($request)),
            $selfUrl,
            $homeUrl,
            $carried,
            $fragment,
            $stateKey,
            $linked,
            $this->config->language,
            $this->config->texts,
            $inputsChanged,
            $this->secret(),
            $uploads,
            $refreshing,
        );
    }

    /**
     * Did the user change an input of the tool since the last run? A table asks:
     * when a changed input gives it other rows, it starts again at its first page.
     *
     * Only the fields of the tool count, not what the library keeps in the form
     * (ticked rows, the page of a table). Without a saved run there is nothing to
     * compare with, and the answer is no.
     *
     * @param array<array-key,mixed> $saved what the last run of this tool kept
     * @param array<array-key,mixed> $form  what the browser sent now
     */
    private static function inputsChanged(array $saved, array $form): bool
    {
        if ($saved === []) {
            return false;
        }

        foreach ($saved + $form as $name => $unused) {
            $name = (string) $name;
            if ($name === '' || $name[0] === '_') {
                continue;
            }

            // An unticked box is not sent at all, and it is not kept either.
            if (self::sameLineBreaks($saved[$name] ?? '') !== self::sameLineBreaks($form[$name] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A browser sends the line breaks of a text of several lines as "\r\n". The
     * page keeps them as "\n". Both are the same text.
     */
    private static function sameLineBreaks(mixed $value): mixed
    {
        if (is_array($value)) {
            // The ticked options of a field that takes several values.
            return array_map(self::sameLineBreaks(...), $value);
        }

        return is_string($value) ? str_replace(["\r\n", "\r"], "\n", $value) : $value;
    }

    /**
     * Was this saved by the tool at this address? Two tools can share a script and
     * a path and differ only in the query string (index.php?page=refund and
     * index.php?page=customers). What one of them saved is never shown to the other.
     *
     * @param array<array-key,mixed> $saved
     */
    private static function savedAt(array $saved, string $selfUrl): bool
    {
        $route = $saved['_route'] ?? '';
        if (!is_string($route) || $route === '') {
            return true;
        }

        parse_str($route, $wanted);
        parse_str((string) parse_url($selfUrl, PHP_URL_QUERY), $present);

        foreach ($wanted as $name => $value) {
            if (!array_key_exists($name, $present) || $present[$name] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * What the "Reset" link of a page carries. It is made from the form token,
     * which never leaves the form, so the code can sit in an address safely.
     */
    public static function resetCode(string $token, string $stateKey): string
    {
        return substr(hash_hmac('sha256', 'reset:' . $stateKey, $token), 0, 24);
    }

    /**
     * Lets a link fill in fields: "?customer-email=ava@example.com", and for a
     * field that takes several values "?status[]=paid&status[]=shipped".
     * Only plain fields can be set this way. Names that start with an underscore
     * belong to the library, so a link can never press a button or tick a table row.
     * What a link fills in is used for this page view only; it is not saved.
     *
     * @param array<array-key,mixed> $saved
     * @param array<array-key,mixed> $query
     *
     * @return array{array<array-key,mixed>, array<array-key,true>} the bag, and the names that came from the link
     */
    private static function prefill(array $saved, array $query): array
    {
        $linked = [];
        $count = 0;
        foreach ($query as $name => $value) {
            $name = (string) $name;
            if ($name === '' || $name[0] === '_' || !self::fitsALink($value) || ++$count > 50) {
                continue;
            }
            $saved[$name] = $value;
            $linked[$name] = true;
        }

        return [$saved, $linked];
    }

    /**
     * What a link may put into one field: a text, or a plain list of texts. Both
     * are limited to what an address can reasonably carry.
     */
    private static function fitsALink(mixed $value): bool
    {
        if (is_string($value)) {
            return strlen($value) <= 2000;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > 100) {
            return false;
        }

        $bytes = 0;
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
            $bytes += strlen($item);
        }

        return $bytes <= 2000;
    }

    /**
     * Messages that the run before rerun() left for this page view.
     *
     * @return array<array-key,mixed>
     */
    private function takeMessages(string $stateKey): array
    {
        $flash = $this->session->get('flash');
        if (!is_array($flash) || !isset($flash[$stateKey])) {
            return [];
        }

        $messages = $flash[$stateKey];
        unset($flash[$stateKey]);
        $this->session->set('flash', $flash);

        return is_array($messages) ? $messages : [];
    }

    /**
     * The page script reports how the user left the parts of a page that open and
     * close. The browser opens and closes them without a request, so no run of the
     * tool would tell the server. This keeps what the report says with the saved
     * inputs of the tool, and runs nothing.
     *
     * @param array<array-key,mixed> $states what every tool of the session saved
     * @param array<array-key,mixed> $saved  what this tool saved, at this address
     * @param bool                   $noRun  nothing was saved by a run of the tool yet
     * @param array<array-key,mixed> $form   what the page script sent
     */
    private function noteLayout(array $states, string $stateKey, array $saved, bool $noRun, array $form, string $selfUrl): Response
    {
        $reported = Page::layout($form);

        if ($reported !== []) {
            foreach ($reported as $name => $parts) {
                // What the report names is as it says. What it does not name stays as it was.
                $saved[$name] = $parts + (is_array($saved[$name] ?? null) ? $saved[$name] : []);
            }
            // Many reports with made-up names add up to no more than one form could hold.
            $saved = Page::layout($saved) + $saved;

            if ($noRun) {
                $saved['_no_run'] = '1';
            }

            // The page script posts to the address of the form, like the form itself.
            $route = (string) parse_url($selfUrl, PHP_URL_QUERY);

            // A report must never cost the user their saved inputs. One that would
            // make the whole more than a session keeps for a tool is dropped. The
            // form still carries what it said, and the next run saves it.
            if (strlen(serialize($saved + ['_route' => $route])) <= self::MAX_STATE_BYTES) {
                $this->store($states, $stateKey, $saved, $route);
            }
        }

        return new Response(204, '', self::headers());
    }

    private function saveState(Page $page): void
    {
        $states = $this->session->get('state');

        $this->store(is_array($states) ? $states : [], $page->stateKey, $page->state(), (string) parse_url($page->address(), PHP_URL_QUERY));
    }

    /**
     * Files what a tool keeps for its next page view.
     *
     * @param array<array-key,mixed> $states what every tool of the session saved
     * @param array<array-key,mixed> $state  what this tool keeps
     * @param string                 $route  the query string of the address that saves it
     */
    private function store(array $states, string $stateKey, array $state, string $route): void
    {
        // Remember which address saved this. See savedAt().
        if ($state !== [] && $route !== '') {
            $state['_route'] = $route;
        }

        if (strlen(serialize($state)) > self::MAX_STATE_BYTES) {
            // Ticked rows are the one thing that grows by the thousand without
            // anybody typing. They go first, so that the inputs can stay.
            unset($state['_sel'], $state['_kept']);
        }

        // Taken out and put back, the tool that was used last stands at the end.
        unset($states[$stateKey]);
        if ($state !== [] && strlen(serialize($state)) <= self::MAX_STATE_BYTES) {
            $states[$stateKey] = $state;
        }

        // A script that the web server runs directly is filed under its path, and a
        // web server takes any path behind a script. Without a limit somebody who is
        // logged in could fill their session with one saved form for every path.
        if (count($states) > self::MAX_STATES) {
            // The names stay as they are: a tool can be called "2024".
            $states = array_slice($states, -self::MAX_STATES, null, true);
        }

        $this->session->set('state', $states);
    }

    /**
     * Tells the log callback which button was pressed, by whom and with which inputs.
     *
     * @return bool false when there was something to log and the callback failed: it threw, or it returned false
     */
    private function audit(Page $page): bool
    {
        $action = $page->action();
        if ($action === null || $this->config->log === null) {
            return true;
        }

        try {
            $written = self::call($this->config->log, [
                'time' => date(DATE_ATOM),
                'user' => $page->user,
                'tool' => $page->toolId,
                'action' => $action['label'],
                'key' => $action['key'],
                'confirmed' => $action['confirmed'],
                'inputs' => $page->values(),
                'error' => $page->failure()?->getMessage(),
                // What the page said is wrong with its inputs: the messages by the key of
                // their field. A press that found something wrong has usually not done its work.
                'field_errors' => $page->fieldErrors(),
                // For a download: which file, and how large. No size means the file could not be made.
                'download' => $action['file'] === null ? null : [
                    'file' => $action['file'],
                    'bytes' => $page->fileToSend() === null ? null : strlen($page->fileToSend()->body),
                ],
            ]);

            if ($written === false) {
                // What file_put_contents() and many loggers answer when nothing was written.
                error_log(sprintf('Script UI: the log callback returned false for "%s" in the tool "%s".', $action['label'], $page->toolId));

                return false;
            }

            return true;
        } catch (\Throwable $error) {
            error_log(sprintf('Script UI: the log callback failed for "%s" in the tool "%s": %s', $action['label'], $page->toolId, $error));

            return false;
        }
    }

    /**
     * Which tool an address means.
     *
     * @return array{id: ?string, home: string, url: \Closure(string):string}|null null when the address is not ours
     */
    private static function route(Request $request, ?string $base): ?array
    {
        $path = $request->path;

        if ($base === null) {
            $id = $request->query['_tool'] ?? '';
            if (!is_string($id)) {
                return null;
            }

            return [
                'id' => $id === '' ? null : $id,
                'home' => $path,
                'url' => static fn (string $toolId): string => $path . '?_tool=' . rawurlencode($toolId),
            ];
        }

        $base = trim($base, '/');
        $base = $base === '' ? '' : '/' . $base;

        if ($base !== '' && $path !== $base && !str_starts_with($path, $base . '/')) {
            return null;
        }

        $rest = trim(substr($path, strlen($base)), '/');
        if (str_contains($rest, '/')) {
            return null;
        }

        return [
            'id' => $rest === '' ? null : $rest,
            'home' => $base . '/',
            'url' => static fn (string $toolId): string => $base . '/' . rawurlencode($toolId),
        ];
    }

    /**
     * Calls a callback of the application. Whatever it prints is thrown away:
     * nothing may reach the browser before the page and its headers do.
     */
    private static function call(\Closure $callback, mixed ...$arguments): mixed
    {
        $level = ob_get_level();
        ob_start();

        try {
            return $callback(...$arguments);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    /** Runs a tool file. It sees no variables of the library, only its own. */
    private static function load(string $__scriptUiToolFile): void
    {
        require $__scriptUiToolFile;
    }

    private function token(): string
    {
        $token = $this->session->get('csrf');

        if (!is_string($token) || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    /**
     * A secret that stays on the server. A table makes with it what its form
     * carries about rows, so that the form says nothing about them. The form token
     * cannot do that job: every page shows it.
     */
    private function secret(): string
    {
        $secret = $this->session->get('secret');

        if (!is_string($secret) || preg_match('/^[0-9a-f]{64}$/D', $secret) !== 1) {
            $secret = bin2hex(random_bytes(32));
            $this->session->set('secret', $secret);
        }

        return $secret;
    }

    /**
     * A page that only says something. Heading, text and the text of the link are ids of built-in texts.
     *
     * @param array{string, string}|null $link    address and text id of a link to offer
     * @param array<string,string>       $headers more headers for this response
     */
    private function message(int $status, string $heading, string $text, ?array $link = null, array $headers = []): Response
    {
        $nonce = self::nonce();
        $texts = $this->config->builtInTexts();

        return new Response(
            $status,
            Renderer::message(
                $texts->get($heading),
                $texts->get($text),
                $this->config,
                $nonce,
                $link === null ? null : [$link[0], $texts->get($link[1])],
            ),
            self::headers($nonce) + $headers,
        );
    }

    private static function nonce(): string
    {
        return base64_encode(random_bytes(18));
    }

    /**
     * Headers for every response. The policy allows nothing from other sites, and
     * only the one style block and the one script that carry this response's nonce.
     *
     * @return array<string,string>
     */
    private static function headers(?string $nonce = null): array
    {
        $policy = "default-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; connect-src 'self'; img-src 'self' data:";
        if ($nonce !== null) {
            $policy .= "; style-src 'nonce-" . $nonce . "'; script-src 'nonce-" . $nonce . "'";
        }

        return [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Security-Policy' => $policy,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
    }
}
