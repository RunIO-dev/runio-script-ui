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

    /** A message that waits in the session for the reloaded page is cut to this length. */
    private const MAX_MESSAGE_BYTES = 4096;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
    ) {
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
            return $this->message(404, 'Not found', 'There is nothing at this address.');
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
            return $this->message(404, 'Not found', 'There is no tool at this address.', [$route['home'], 'All tools']);
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
            // A run that failed half way must not overwrite what was saved before.
            if ($failure === null) {
                $this->saveState($page);
            }
            $this->audit($page);
        }

        if ($page->tookOver()) {
            // The tool sent an answer of its own, a file for example. Nothing is added to it.
            return new Response(alreadySent: true);
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

            return $this->message(
                403,
                'Only available on this computer',
                "No login check is set up, so these tools only answer to a browser on the computer they run on (http://localhost), "
                . "and not through a proxy or a tunnel.\n\n"
                . 'To use them from anywhere else, add a login check: pass auth: to Runio\ScriptUi\serve() or to Runio\ScriptUi\configure(). '
                . 'It receives the request and returns the name of the user, or null to refuse.',
            );
        }

        try {
            $result = self::call($this->config->auth, $request);
        } catch (\Throwable $error) {
            // Never show why to someone who is not logged in.
            error_log('Script UI: the login check failed: ' . $error);

            return $this->message(500, 'The login check failed', 'Nobody is let in until it works again. The details are in the error log of the server.');
        }

        if ($result === null || $result === false || $result === '' || $result === []) {
            return $this->message(403, 'Not allowed', 'You do not have access to this page.');
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
            return new Response(
                405,
                Renderer::message('Method not allowed', 'This page answers GET and POST requests.', $this->config->name, $nonce = self::nonce()),
                self::headers($nonce) + ['Allow' => 'GET, HEAD, POST'],
            );
        }

        $token = $this->token();
        $isPost = $request->method === 'POST';

        if ($isPost) {
            $sent = $request->post['_token'] ?? null;
            $crossSite = strtolower((string) $request->header('sec-fetch-site')) === 'cross-site';

            if (!is_string($sent) || !hash_equals($token, $sent) || $crossSite) {
                return $this->message(
                    419,
                    'This page has expired',
                    'Your session ended, or the form was not sent from this page. Open the page again and repeat what you did.',
                    [$selfUrl, 'Open the page again'],
                );
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
            }

            return new Response(303, '', self::headers() + ['Location' => $selfUrl]);
        }

        if ($isPost) {
            $in = $request->post;
            $linked = [];
            $carried = [];
        } else {
            $saved = $states[$stateKey] ?? [];
            $saved = is_array($saved) && self::savedAt($saved, $selfUrl) ? $saved : [];
            unset($saved['_route']);
            [$in, $linked] = self::prefill($saved, $request->query);
            $carried = $this->takeMessages($stateKey);
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
            $request->header('x-script-ui') === 'fragment',
            $stateKey,
            $linked,
        );
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
     * Lets a link fill in fields: "?customer-email=ava@example.com".
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
            if ($name === '' || $name[0] === '_' || !is_string($value) || strlen($value) > 2000 || ++$count > 50) {
                continue;
            }
            $saved[$name] = $value;
            $linked[$name] = true;
        }

        return [$saved, $linked];
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

    private function saveState(Page $page): void
    {
        $states = $this->session->get('state');
        $states = is_array($states) ? $states : [];
        $state = $page->state();

        // Remember which address saved this. See savedAt().
        $route = (string) parse_url($page->address(), PHP_URL_QUERY);
        if ($state !== [] && $route !== '') {
            $state['_route'] = $route;
        }

        if ($state === [] || strlen(serialize($state)) > self::MAX_STATE_BYTES) {
            unset($states[$page->stateKey]);
        } else {
            $states[$page->stateKey] = $state;
        }

        $this->session->set('state', $states);
    }

    /** Tells the log callback which button was pressed, by whom and with which inputs. */
    private function audit(Page $page): void
    {
        $action = $page->action();
        if ($action === null || $this->config->log === null) {
            return;
        }

        try {
            self::call($this->config->log, [
                'time' => date(DATE_ATOM),
                'user' => $page->user,
                'tool' => $page->toolId,
                'action' => $action['label'],
                'key' => $action['key'],
                'confirmed' => $action['confirmed'],
                'inputs' => $page->values(),
                'error' => $page->failure()?->getMessage(),
            ]);
        } catch (\Throwable $error) {
            error_log(sprintf('Script UI: the log callback failed for "%s" in the tool "%s": %s', $action['label'], $page->toolId, $error));
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
     * @param array{string, string}|null $link
     */
    private function message(int $status, string $heading, string $text, ?array $link = null): Response
    {
        $nonce = self::nonce();

        return new Response(
            $status,
            Renderer::message($heading, $text, $this->config->name, $nonce, $link),
            self::headers($nonce),
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
