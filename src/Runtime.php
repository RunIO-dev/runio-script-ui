<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Session\NativeSession;
use Runio\ScriptUi\Signal\Stop;

/**
 * Connects the plain functions (title(), text(), button() ...) to the page that
 * is being drawn right now, and starts everything up on a real web request.
 *
 * A tool can run in two ways:
 *  - served: serve() picks the file and runs it;
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
        if (self::$page !== null) {
            throw new \LogicException('serve() cannot be called from inside a tool.');
        }
        self::assertWeb();

        $request = Request::fromGlobals();
        $kernel = new Kernel(
            self::config()->with($auth, $log, $debug, $name, $language, $texts, $uploads, $allow, $variables),
            new NativeSession($request->secure),
        );

        // After a fatal error inside a tool the page it drew so far is still sent.
        self::hook();

        $kernel->serve($request, new ToolFinder($directory), $base, self::webRoot())->send();
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

            $page->release();
            $response = $kernel->finish($page);
            // PHP is ending. An application that keeps the session never gets to
            // save it, and what the page keeps was just written into it.
            $kernel->persist();
            $response->send();
        });
    }

    private static function assertWeb(): void
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            throw new \LogicException(
                'Script UI draws in a browser, and this script runs on the command line. '
                . 'Start a server with "php vendor/bin/script-ui serve <folder>" and open the address it prints.',
            );
        }
    }
}
