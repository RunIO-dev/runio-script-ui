<?php

declare(strict_types=1);

/**
 * A stand-in for an application that is built on a framework, for
 * tests/respond.spec.mjs. It does what Symfony and Laravel do around a
 * controller:
 *
 *  - it keeps a session of its own, as JSON in a file, and not PHP's;
 *  - it lets the controller hand the page back, and sends the page itself:
 *    with a header of its own, with the cookie of a session that just began,
 *    and with a type for the page in place of any type that was set before;
 *  - it saves its session before it sends the page, and closes it as the
 *    first thing when PHP shuts down, as Symfony does with PHP's own session.
 *    What is put into it after that is lost;
 *  - it reports a fatal error itself, with a page of its own.
 *
 * Symfony and Laravel themselves are tried in e2e/frameworks. They have to be
 * downloaded first, and this needs nothing.
 */

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

/** The session of the application. It has the methods of Laravel's: get(), put() and save(). */
final class AppSession
{
    /** @var array<string,mixed> */
    private array $data = [];

    private bool $closed = false;

    /** @param string|null $cookie the cookie of a session that began with this request */
    private function __construct(private readonly string $file, public readonly ?string $cookie)
    {
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            $this->data = is_array($data) ? $data : [];
        }
    }

    public static function start(): self
    {
        // The sessions of earlier test runs are cleared away.
        foreach (glob(sys_get_temp_dir() . '/script-ui-e2e-app-*.json') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }

        $id = $_COOKIE['app_session'] ?? '';
        $cookie = null;
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) {
            $id = bin2hex(random_bytes(16));
            $cookie = 'app_session=' . $id . '; Path=/; HttpOnly; SameSite=Lax';
        }

        return new self(sys_get_temp_dir() . '/script-ui-e2e-app-' . $id . '.json', $cookie);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function flush(): void
    {
        $this->data = [];
    }

    public function save(): void
    {
        if ($this->closed) {
            return;
        }

        // What cannot be written as JSON cannot be kept: this throws.
        file_put_contents($this->file, json_encode($this->data, JSON_THROW_ON_ERROR), LOCK_EX);
    }

    /** Writes the session a last time. Nothing reaches the file after this. */
    public function close(): void
    {
        $this->save();
        $this->closed = true;
    }
}

/**
 * Sends a page of the application, the way a framework does it.
 *
 * @param array<string,string> $headers
 */
function send_page(AppSession $session, int $status, array $headers, string $body): void
{
    $session->save();

    // When something was sent before, a framework leaves the headers alone.
    if (!headers_sent()) {
        http_response_code($status);
        // Every page gets a type, and it takes the place of one that was set with
        // header() before. Every other header is added to what is there.
        foreach ($headers + ['Content-Type' => 'text/html; charset=UTF-8'] as $name => $value) {
            header($name . ': ' . $value, strcasecmp($name, 'Content-Type') === 0);
        }
        header('X-App: stand-in');
        if ($session->cookie !== null) {
            header('Set-Cookie: ' . $session->cookie, false);
        }
    }

    echo $body;
}

$session = AppSession::start();
// Before the controller runs, and so before any step the library adds to the shutdown.
register_shutdown_function($session->close(...));
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    // Like Symfony: the page is printed, into whatever buffer is open.
    if (!headers_sent()) {
        // PHP has set the status itself by now and says so with a warning. From
        // then on the last error PHP knows of is that warning, and not the fatal
        // error: whoever asks PHP later why it ended gets the wrong answer.
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-App: stand-in');
    }
    echo '<!doctype html><html lang="en"><title>Error</title><h1 id="app-error">The application broke down.</h1></html>';
});
$path = (string) parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

// What goes to the error log is kept in a file, so that a test can read what
// the library wrote there. "/log" hands it out and starts it again.
$log = sys_get_temp_dir() . '/script-ui-e2e-app-error.log';
ini_set('error_log', $log);
if ($path === '/log') {
    header('Content-Type: text/plain; charset=utf-8');
    echo is_file($log) ? (string) file_get_contents($log) : '';
    @unlink($log);

    return;
}

// Pages of the application itself: who is logged in is its business.
if ($path === '/login') {
    $session->put('user', is_string($_GET['as'] ?? null) ? $_GET['as'] : 'ava');
    send_page($session, 302, ['Location' => '/tools'], '');

    return;
}
if ($path === '/logout') {
    $session->flush();
    send_page($session, 302, ['Location' => '/tools'], '');

    return;
}

// Headers the application sets early, before the controller runs. They are its own.
header('X-App-Early: one', false);
header('X-App-Early: two', false);
header('X-App-Mode: application');
header('X-App-Kept: once');

// The controller.
$buffers = ob_get_level();
$page = Runio\ScriptUi\respond(
    dirname(__DIR__) . '/tools',
    base: '/tools',
    auth: static fn (): ?string => $session->get('user'),
    variables: ['app' => 'the application'],
    session: $session,
);

// The application finds its output buffers as it left them.
send_page($session, $page->status, $page->headers + ['X-Buffers' => $buffers . ' before, ' . ob_get_level() . ' after'], $page->body);
