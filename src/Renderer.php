<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Wraps content in the HTML document: header bar, styles and script.
 *
 * Styles and script are written into the page, so there is nothing to publish
 * or link. Both carry the nonce that the Content-Security-Policy allows.
 *
 * @internal
 */
final class Renderer
{
    private const ICON = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 16 16%22%3E%3Crect width=%2216%22 height=%2216%22 rx=%224%22 fill=%22%231b64be%22/%3E%3Cpath d=%22M4 5h8M4 8h8M4 11h5%22 stroke=%22%23fff%22 stroke-width=%221.6%22 stroke-linecap=%22round%22/%3E%3C/svg%3E';

    /** @var array<string,string> */
    private static array $assets = [];

    /** What the browser tab says. */
    public static function title(Page $page, Config $config): string
    {
        return $page->documentTitle() . ' · ' . $config->name;
    }

    /** The page of one tool: everything the script drew, inside the one form. */
    public static function tool(Page $page, Config $config, string $token, string $nonce): string
    {
        // The address without what a link filled in. See Page::address().
        $address = $page->address();

        $form = '<form id="sui-form" method="post" action="' . Text::e($address) . '" autocomplete="off" novalidate>'
            . '<input type="hidden" name="_token" value="' . Text::e($token) . '">'
            // Pressing Enter in a field submits through the first submit button of the form.
            // This one has no name, so Enter reruns the page and never triggers an action.
            . '<button type="submit" class="sui-default" tabindex="-1" aria-hidden="true"></button>'
            // The script replaces the inside of this element after every run. It works on
            // this element and not on the form, because a field named "action" or
            // "contains" would hide the form's own property of that name.
            . '<div id="sui-body" class="sui-flow">' . $page->html() . '</div>'
            . '<noscript><p class="sui-noscript"><button type="submit" class="sui-btn sui-btn-default">Update</button></p></noscript>'
            . '</form>';

        $reset = $address . (str_contains($address, '?') ? '&' : '?')
            . '_reset=' . Kernel::resetCode($token, $page->stateKey);

        return self::document(
            title: self::title($page, $config),
            name: $config->name,
            body: $form,
            nonce: $nonce,
            language: $page->language(),
            homeUrl: $page->homeUrl,
            user: $page->user,
            resetUrl: $reset,
            script: true,
        );
    }

    /**
     * The list of all tools.
     *
     * @param array<array-key,Tool>   $tools
     * @param \Closure(string):string $urlFor
     */
    public static function index(array $tools, \Closure $urlFor, Config $config, ?string $user, string $directory, string $nonce): string
    {
        $body = '<div class="sui-flow"><h1 class="sui-title" dir="auto">' . Text::e($config->name) . '</h1>';

        if ($tools === []) {
            $body .= '<div class="sui-callout sui-info" role="status">No tools yet. Put a PHP file into <code>'
                . Text::e($directory) . '</code> and it shows up here.</div>';
        } else {
            $body .= '<div class="sui-tools">';
            foreach ($tools as $tool) {
                $body .= '<a class="sui-card" href="' . Text::e($urlFor($tool->id)) . '"><strong dir="auto">' . Text::e($tool->title) . '</strong>'
                    . ($tool->description === '' ? '' : '<span dir="auto">' . Text::e($tool->description) . '</span>')
                    . '</a>';
            }
            $body .= '</div>';
        }

        return self::document(
            title: $config->name,
            name: $config->name,
            body: $body . '</div>',
            nonce: $nonce,
            language: Text::language($config->name),
            user: $user,
        );
    }

    /**
     * A page that only says something: not allowed, expired, not found.
     *
     * @param array{string, string}|null $link address and text of a link to offer
     */
    public static function message(string $heading, string $text, string $name, string $nonce, ?array $link = null): string
    {
        $body = '<div class="sui-flow sui-message"><h1 class="sui-title">' . Text::e($heading) . '</h1>'
            . '<p class="sui-note">' . Text::lines($text) . '</p>'
            . ($link === null ? '' : '<p class="sui-link-row"><a class="sui-link" href="' . Text::e($link[0]) . '">' . Text::e($link[1]) . '</a></p>')
            . '</div>';

        return self::document(title: $heading . ' · ' . $name, name: $name, body: $body, nonce: $nonce);
    }

    private static function document(
        string $title,
        string $name,
        string $body,
        string $nonce,
        string $language = 'en',
        ?string $homeUrl = null,
        ?string $user = null,
        ?string $resetUrl = null,
        bool $script = false,
    ): string {
        $bar = '';
        if ($homeUrl !== null || $user !== null || $resetUrl !== null) {
            $bar = '<header class="sui-top"><nav class="sui-top-in" aria-label="' . Text::e($name) . '">'
                . ($homeUrl === null ? '' : '<a class="sui-home" href="' . Text::e($homeUrl) . '" dir="auto">' . Text::e($name) . '</a>')
                . '<span class="sui-gap"></span>'
                . ($user === null ? '' : '<span class="sui-user" dir="auto">' . Text::e($user) . '</span>')
                . ($resetUrl === null ? '' : '<a class="sui-reset" href="' . Text::e($resetUrl) . '" title="Forget the saved inputs of this tool">Reset</a>')
                . '</nav></header>';
        }

        $safeNonce = Text::e($nonce);

        return '<!doctype html>' . "\n"
            . '<html lang="' . $language . '" dir="' . (in_array($language, ['ar', 'he'], true) ? 'rtl' : 'ltr') . '">' . "\n"
            . '<head>' . "\n"
            . '<meta charset="utf-8">' . "\n"
            . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
            . '<meta name="robots" content="noindex, nofollow">' . "\n"
            . '<title>' . Text::e($title) . '</title>' . "\n"
            . '<link rel="icon" href="' . self::ICON . '">' . "\n"
            . '<style nonce="' . $safeNonce . '">' . self::asset('app.css') . '</style>' . "\n"
            . '</head>' . "\n"
            . '<body>' . "\n"
            . $bar
            . '<main class="sui-main">' . $body . '</main>' . "\n"
            . ($script ? '<script nonce="' . $safeNonce . '">' . self::asset('app.js') . '</script>' . "\n" : '')
            . '</body>' . "\n"
            . '</html>' . "\n";
    }

    private static function asset(string $name): string
    {
        return self::$assets[$name] ??= (string) file_get_contents(dirname(__DIR__) . '/resources/' . $name);
    }
}
