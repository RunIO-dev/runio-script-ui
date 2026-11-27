<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * How the tools are protected and presented.
 */
final class Config
{
    /**
     * The login check. It receives the request and returns who is asking:
     * a name or id to let them in, null or false to keep them out.
     * Without a login check the tools only answer on the machine they run on.
     */
    public readonly ?\Closure $auth;

    /**
     * Called once for every button a user presses and every file a user downloads, with an array describing the action.
     * When it throws or returns false, the entry counts as not written, and a download is not sent.
     */
    public readonly ?\Closure $log;

    /**
     * @param bool|null              $debug    show error details in the page. null means: only without a login check,
     *                                         where nobody but this computer gets in
     * @param string|null            $name     the name of the tool collection, shown in the header and on the index page.
     *                                         null means "Tools" in the language of the page
     * @param string|null            $language the language of the built-in texts on every page, such as "de".
     *                                         null means: each page follows the language its title is written in
     * @param array<array-key,mixed> $texts    your own built-in texts: language => (id => text)
     */
    public function __construct(
        ?callable $auth = null,
        ?callable $log = null,
        public readonly ?bool $debug = null,
        public readonly ?string $name = null,
        public readonly ?string $language = null,
        public readonly array $texts = [],
    ) {
        Texts::check($language, $texts);

        $this->auth = $auth === null ? null : $auth(...);
        $this->log = $log === null ? null : $log(...);
    }

    /**
     * A copy with the given settings replaced. Settings left out stay as they are.
     *
     * @param array<array-key,mixed>|null $texts
     */
    public function with(
        ?callable $auth = null,
        ?callable $log = null,
        ?bool $debug = null,
        ?string $name = null,
        ?string $language = null,
        ?array $texts = null,
    ): self {
        return new self(
            $auth ?? $this->auth,
            $log ?? $this->log,
            $debug ?? $this->debug,
            $name ?? $this->name,
            $language ?? $this->language,
            $texts ?? $this->texts,
        );
    }

    /** The name of the tool collection, in the language of the page when none was given. */
    public function name(Texts $texts): string
    {
        return $this->name ?? $texts->get('index.title');
    }

    /**
     * The built-in texts for a page that is not a tool: the list of tools, or a
     * page that turns someone away. Without a set language they follow the name
     * of the collection.
     */
    public function builtInTexts(): Texts
    {
        return Texts::load($this->language ?? Text::language($this->name ?? ''), $this->texts);
    }
}
