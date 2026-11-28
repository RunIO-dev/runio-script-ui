<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\FrameworkSession;
use Runio\ScriptUi\Session\NativeSession;
use Runio\ScriptUi\Session\Session;
use Runio\ScriptUi\Signal\Stop;

/**
 * Connects the plain functions (title(), text(), button() ...) to the page that
 * is being drawn right now, and starts everything up on a real web request.
 *
 * A tool can run in two ways:
 *  - served: serve() or respond() picks the file and runs it;
 *  - direct: the web server runs the script itself, and the first function it
 *    calls starts the page. The page is sent when the script ends.
 *
 * @internal
 */
final class Runtime
{
    private static ?Config $config = null;

    private static ?Page $page = null;

    private static ?Kernel $kernel = null;

    private static bool $hooked = false;

    /** respond() was called: the application sends the page, and reports its fatal errors itself. */
    private static bool $handsBack = false;

    /** The web server runs the script itself, and the page is sent when the script ends. */
    private static bool $direct = false;

    public static function config(): Config
    {
        return self::$config ??= new Config();
    }

    /**
     * @param array<array-key,mixed>|null $texts
     */
    public static function configure(?callable $auth, ?callable $log, ?bool $debug, ?string $name, ?string $language = null, ?array $texts = null, ?string $uploads = null, ?callable $allow = null): void
    {
        if (self::$page !== null) {
            // Accepting this quietly would be dangerous: a login check added here
            // would look like protection and do nothing.
            throw new \LogicException(
                'configure() has to be called before the first thing is drawn. '
                . 'When the tools are served with serve(), pass the settings to serve() instead. '
                . 'To limit one tool to some users, pass allow: to serve() or to configure(), or check user() inside the tool.',
            );
        }

        self::$config = self::config()->with($auth, $log, $debug, $name, $language, $texts, $uploads, $allow);
    }

    /** The page being drawn. On a web request without one, the page is started here. */
    public static function page(): Page
    {
        return self::$page ?? self::boot();
    }

    /**
     * @param array<array-key,mixed>|null $texts
     * @param array<array-key,mixed>|null $variables
     */
    public static function serve(
        string $directory,
        ?callable $auth,
        ?callable $log,
        ?string $name,
        ?bool $debug,
        ?string $base,
        ?string $language = null,
        ?array $texts = null,
        ?string $uploads = null,
        ?callable $allow = null,
        ?array $variables = null,
    ): void {
        [$kernel, $request] = self::start('serve', self::config()->with($auth, $log, $debug, $name, $language, $texts, $uploads, $allow, $variables), null);

        $kernel->serve($request, new ToolFinder($directory), $base, self::webRoot())->send();
    }

    /**
     * serve() for an application that sends its pages itself, as one built on
     * Symfony or Laravel does: the page is handed back and not sent.
     *
     * @param array<array-key,mixed>|null $texts
     * @param array<array-key,mixed>|null $variables
     * @param object|null                 $session   the session of the application. null: PHP's own
     */
    public static function respond(
        string $directory,
        ?callable $auth,
        ?callable $log,
        ?string $name,
        ?bool $debug,
        ?string $base,
        ?string $language = null,
        ?array $texts = null,
        ?string $uploads = null,
        ?callable $allow = null,
        ?array $variables = null,
        ?object $session = null,
    ): Response {
        [$kernel, $request] = self::start(
            'respond',
            self::config()->with($auth, $log, $debug, $name, $language, $texts, $uploads, $allow, $variables),
            // Looked at before anything else: an object that is no session is a
            // mistake in the code, wherever that code runs.
            $session === null ? null : new FrameworkSession($session),
        );
        self::$handsBack = true;

        // A tool may close its page and print an answer of its own. That answer
        // comes to rest here and is handed back like a page. Printed straight
        // to the browser it would leave before the application is done: a
        // framework puts its own type on what it sends after that, where PHP
        // still holds the output back, and Symfony cannot set its cookie.
        $headers = headers_list();
        ob_start();
        $level = ob_get_level();

        try {
            $response = $kernel->serve($request, new ToolFinder($directory), $base, self::webRoot());
        } catch (\Throwable $error) {
            self::passOn($level);

            throw $error;
        }

        if (!$response->alreadySent || ob_get_level() < $level) {
            if ($response->alreadySent) {
                // The tool closed every buffer there is, this one too, and its
                // answer is on its way. Nothing can be done about that one, and
                // nobody sees in a browser why the cookie of the application or
                // its headers are missing. So it is written down.
                error_log('Script UI: a tool closed every output buffer and sent its answer itself, past respond(). '
                    . 'The application can add no cookie and no header to that answer. '
                    . 'Hand a file to download(), or close the buffer of the page alone, with one ob_end_clean().');
            }
            self::passOn($level);

            return $response;
        }

        while (ob_get_level() > $level && @ob_end_flush()) {
        }
        $status = http_response_code();

        return new Response(is_int($status) ? $status : 200, (string) ob_get_clean(), self::takeHeaders($headers));
    }

    /**
     * The kernel for the request PHP is working on, and that request.
     *
     * @return array{Kernel, Request}
     */
    private static function start(string $caller, Config $config, ?Session $session): array
    {
        if (self::$page !== null) {
            throw new \LogicException($caller . '() cannot be called from inside a tool.');
        }
        self::assertWeb($caller);

        $request = Request::fromGlobals();
        $kernel = new Kernel($config, $session ?? new NativeSession($request->secure));

        // After a fatal error inside a tool the page it drew so far is still sent.
        self::hook();

        return [$kernel, $request];
    }

    /** Closes the buffer of respond(). What is in it goes on to where it was going. */
    private static function passOn(int $level): void
    {
        while (ob_get_level() >= $level && @ob_end_flush()) {
        }
    }

    /**
     * The headers a tool set for an answer of its own, taken out of PHP's
     * hands: the application sends them with the answer.
     *
     * @param list<string> $before the headers that were set before the tool ran
     *
     * @return array<string,string>
     */
    private static function takeHeaders(array $before): array
    {
        $now = headers_list();
        $headers = [];
        // A name in lower case => the name as the tool wrote it first.
        $names = [];
        foreach (array_diff($now, $before) as $line) {
            $parts = explode(':', $line, 2);
            $name = trim($parts[0]);
            $known = strtolower($name);
            // A cookie cannot share a line with another one. Cookies stay with
            // PHP, which sends them as well.
            if (count($parts) !== 2 || $known === 'set-cookie') {
                continue;
            }

            // A response holds one value for a name. Several lines of one
            // header mean the same as one line that lists their values.
            $names[$known] ??= $name;
            $value = trim($parts[1]);
            $headers[$names[$known]] = isset($headers[$names[$known]]) ? $headers[$names[$known]] . ', ' . $value : $value;
        }

        foreach ($names as $name) {
            header_remove($name);
        }

        // PHP lets go of every line of a name at once. A line that the
        // application had set under such a name, and that the tool left as it
        // was, goes back.
        foreach (array_intersect($before, $now) as $line) {
            if (isset($names[strtolower(trim(explode(':', $line, 2)[0]))])) {
                header($line, false);
            }
        }

        return $headers;
    }

    /**
     * The folder that the web server hands files out from by their names, or
     * null where it does not do that.
     */
    private static function webRoot(): ?string
    {
        // "script-ui serve" sends every address to one script, whatever file it
        // names. That server says where the tools are in this variable.
        if (PHP_SAPI === 'cli-server' && getenv('SCRIPT_UI_TOOLS') !== false) {
            return null;
        }

        $root = $_SERVER['DOCUMENT_ROOT'] ?? null;

        return is_string($root) && $root !== '' ? $root : null;
    }

    /**
     * Makes a page the current one.
     *
     * @return array{?Page, ?Kernel} what was current before, for leave()
     */
    public static function enter(Page $page, Kernel $kernel): array
    {
        $previous = [self::$page, self::$kernel];
        self::$page = $page;
        self::$kernel = $kernel;

        return $previous;
    }

    /** @param array{?Page, ?Kernel} $previous */
    public static function leave(array $previous): void
    {
        [self::$page, self::$kernel] = $previous;
    }

    /** Is this the page that is being drawn right now? */
    public static function isCurrent(Page $page): bool
    {
        return self::$page === $page;
    }

    /** Forgets everything. For tests. */
    public static function reset(): void
    {
        self::$config = null;
        self::$page = null;
        self::$kernel = null;
        self::$handsBack = false;
        self::$direct = false;
    }

    /** Direct mode: the script was opened in the browser and just called its first function. */
    private static function boot(): Page
    {
        self::assertWeb();

        if (headers_sent($file, $line)) {
            throw new \LogicException(sprintf(
                'Output already started at %s:%d. Call the first Script UI function before anything is printed.',
                $file,
                $line,
            ));
        }

        $request = Request::fromGlobals();
        $kernel = new Kernel(self::config(), new NativeSession($request->secure));

        $script = is_string($_SERVER['SCRIPT_FILENAME'] ?? null) ? $_SERVER['SCRIPT_FILENAME'] : 'tool';
        $toolId = Keys::slug(pathinfo($script, PATHINFO_FILENAME));

        // The application may route with its own query parameters (?page=refund),
        // so they stay in the address the form posts to. Ours never do.
        $query = array_filter(
            $request->query,
            static fn (int|string $name): bool => !str_starts_with((string) $name, '_'),
            ARRAY_FILTER_USE_KEY,
        );
        $selfUrl = $request->path . ($query === [] ? '' : '?' . http_build_query($query));

        // Many scripts share a file name (index.php), and one front controller can
        // show many tools. So what a tool saves is filed under its script and its
        // path together. (The query string is not part of it: a link may add values
        // for fields there. Tools that differ only in the query string are kept
        // apart by Kernel::savedAt().)
        $stateKey = $toolId . '~' . substr(hash('sha256', $script . "\n" . $request->path), 0, 12);

        // The access check, when there is one, is asked about the file and not about
        // the id: see Kernel::askedAs().
        $page = $kernel->begin($request, $toolId, $selfUrl, null, $stateKey, $script);
        if ($page instanceof Response) {
            $page->send();
            exit;
        }

        self::enter($page, $kernel);
        self::$direct = true;
        // Stays in force until the script ends. That is when the page is sent.
        Kernel::quiet($page->debug);
        $page->capture();
        self::hook();

        // An error nobody caught ends the script. Keep it, so the page can show it.
        set_exception_handler(static function (\Throwable $error): void {
            if (!$error instanceof Stop) {
                self::$page?->fail($error);
            }
        });

        return $page;
    }

    /**
     * When PHP shuts down with a page still open, finish it and send it. That
     * is a script on its own that came to its end, or a fatal error in any tool.
     * (A served tool that calls exit is seen earlier: Kernel::afterExit().)
     */
    private static function hook(): void
    {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;

        register_shutdown_function(static function (): void {
            $page = self::$page;
            $kernel = self::$kernel;
            if ($page === null || $kernel === null) {
                return;
            }
            self::$page = null;
            self::$kernel = null;

            $fatal = error_get_last();
            if ($fatal !== null && !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                $fatal = null;
            }
            // A script on its own comes here when it ends, in whatever way. A
            // served tool comes here in one way only: PHP broke down inside it.
            // (Its normal end and exit are seen in Kernel::execute().) The last
            // error does not always say so: a warning in an earlier step of the
            // shutdown takes the place of the fatal error.
            $died = $fatal !== null || !self::$direct;
            if ($died && $page->failure() === null) {
                $page->fail($fatal !== null
                    ? new \ErrorException($fatal['message'], 0, $fatal['type'], $fatal['file'], $fatal['line'])
                    : new \ErrorException('PHP ended while the tool ran. The error log of PHP says why.'));
            }

            if ($died && self::$handsBack) {
                // An application reports a fatal error itself, as it does on each
                // of its pages. Laravel has sent its error page by now, and Symfony
                // has printed one into the buffer of this page, which PHP empties
                // into the answer when it ends. The page of the tool stays out of
                // it: two documents would leave as one answer.
                // What is to be logged about the run is still logged.
                $kernel->finish($page);
                $kernel->persist();

                return;
            }

            $page->release();
            $response = $kernel->finish($page);
            // PHP is ending. An application that called respond() never gets to
            // save its session, and what the page keeps was just written into it.
            $kernel->persist();
            $response->send();
        });
    }

    private static function assertWeb(string $caller = 'serve'): void
    {
        if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
            return;
        }

        if ($caller === 'respond') {
            throw new \LogicException(
                'respond() takes the request from PHP itself ($_SERVER, $_GET, $_POST and $_FILES), and on the command line PHP has none. '
                . 'The test client of a framework runs there too and does not fill them in. '
                . 'Try the tools through a web server: in a browser, or with a browser test.',
            );
        }

        throw new \LogicException(
            'Script UI draws in a browser, and this script runs on the command line. '
            . 'Start a server with "php vendor/bin/script-ui serve <folder>" and open the address it prints.',
        );
    }
}
