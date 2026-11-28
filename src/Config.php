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
     * Who may open which tool. It receives what the login check returned (null
     * when that only said true, or when there is none) and the id of a tool,
     * and answers true or false. Without it everybody who gets past the login
     * check may open every tool.
     */
    public readonly ?\Closure $allow;

    /**
     * @param bool|null              $debug    show error details in the page. null means: only without a login check,
     *                                         where nobody but this computer gets in
     * @param string|null            $name     the name of the tool collection, shown in the header and on the index page.
     *                                         null means "Tools" in the language of the page
     * @param string|null            $language the language of the built-in texts on every page, such as "de".
     *                                         null means: each page follows the language its title is written in
     * @param array<array-key,mixed> $texts    your own built-in texts: language => (id => text)
     * @param string|null            $uploads  the folder that keeps uploaded files until a tool is done with them.
     *                                         null means a folder of its own inside PHP's temp folder
     * @param callable|null          $allow    who may open which tool: see the property
     * @param array<array-key,mixed> $variables variables for every tool of a folder: name => value
     */
    public function __construct(
        ?callable $auth = null,
        ?callable $log = null,
        public readonly ?bool $debug = null,
        public readonly ?string $name = null,
        public readonly ?string $language = null,
        public readonly array $texts = [],
        public readonly ?string $uploads = null,
        ?callable $allow = null,
        public readonly array $variables = [],
    ) {
        Texts::check($language, $texts);
        self::checkVariables($variables);

        $this->auth = $auth === null ? null : $auth(...);
        $this->log = $log === null ? null : $log(...);
        $this->allow = $allow === null ? null : $allow(...);
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
        ?string $uploads = null,
        ?callable $allow = null,
        ?array $variables = null,
    ): self {
        return new self(
            $auth ?? $this->auth,
            $log ?? $this->log,
            $debug ?? $this->debug,
            $name ?? $this->name,
            $language ?? $this->language,
            $texts ?? $this->texts,
            $uploads ?? $this->uploads,
            $allow ?? $this->allow,
            $variables ?? $this->variables,
        );
    }

    /**
     * A tool gets each value as a variable of that name, so every key has to be
     * a name that PHP takes for a variable. Said here, where the tools are set
     * up, and not later inside a tool.
     *
     * @param array<array-key,mixed> $variables
     */
    private static function checkVariables(array $variables): void
    {
        foreach (array_keys($variables) as $name) {
            if (!is_string($name)) {
                throw new \InvalidArgumentException(sprintf(
                    'variables: needs a name for every value: [\'orders\' => $orders] makes $orders in every tool. The value at %d has none.',
                    $name,
                ));
            }

            if (in_array($name, ['this', 'GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_SESSION', '_REQUEST', '_ENV'], true)) {
                throw new \InvalidArgumentException(sprintf('variables: "%s" belongs to PHP and cannot be given to a tool. Choose another name.', $name));
            }

            // The names PHP itself takes: a letter or "_" first, then letters, digits and "_".
            // Letters of any script count, which PHP reads as the bytes above 127.
            if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'variables: "%s" cannot be the name of a variable. A name starts with a letter or "_", and holds letters, digits and "_" only.',
                    $name,
                ));
            }
        }
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
