<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * Finds the tools in a folder. Every PHP file directly inside it is a tool,
 * and the file name without ".php" is its id.
 *
 * Files whose name starts with an underscore are helpers, not tools.
 * The first comment in a file is its title; more comment lines describe it.
 */
final class ToolFinder
{
    /** An id only ever contains these characters, so it can never point outside the folder. */
    private const ID = '/^[a-z0-9][a-z0-9_-]{0,63}$/D';

    private const HEAD_BYTES = 4096;

    private const MAX_DESCRIPTION = 240;

    public readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    /** @return array<array-key, Tool> id => tool, sorted by title */
    public function all(): array
    {
        $tools = [];
        foreach ($this->ids() as $id) {
            $tools[$id] = $this->describe($id);
        }

        uasort($tools, static fn (Tool $a, Tool $b): int => strnatcasecmp($a->title, $b->title) ?: strcmp($a->id, $b->id));

        return $tools;
    }

    /**
     * The id of the tool that a file of this name is: "refund" for "refund.php".
     * null when no tool has such a file name.
     */
    public static function idOf(string $fileName): ?string
    {
        if (!str_ends_with($fileName, '.php')) {
            return null;
        }

        $id = substr($fileName, 0, -4);

        return preg_match(self::ID, $id) === 1 ? $id : null;
    }

    public function find(string $id): ?Tool
    {
        // The id is looked up in the list of real file names. It is never glued
        // into a path and tried, so "../x" or a name in another case finds nothing.
        return in_array($id, $this->ids(), true) ? $this->describe($id) : null;
    }

    /** @return list<string> */
    private function ids(): array
    {
        if (!is_dir($this->directory) || !is_readable($this->directory)) {
            return [];
        }

        $ids = [];
        foreach (scandir($this->directory) ?: [] as $name) {
            $id = self::idOf($name);
            if ($id !== null && is_file($this->directory . DIRECTORY_SEPARATOR . $name)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function describe(string $id): Tool
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . $id . '.php';
        $head = is_readable($file) ? (string) file_get_contents($file, false, null, 0, self::HEAD_BYTES) : '';
        [$title, $description] = self::header($head);

        return new Tool($id, $file, $title ?? Text::humanise($id), $description);
    }

    /**
     * Reads the title and the description from the first comment of a PHP file.
     *
     * @return array{?string, string}
     */
    private static function header(string $head): array
    {
        if (preg_match('/\A(?:#![^\n]*\n)?\s*<\?php\s/', $head, $match) !== 1) {
            return [null, ''];
        }

        $rest = substr($head, strlen($match[0]));
        $rest = (string) preg_replace('/\A\s*(?:declare\s*\([^)]*\)\s*;)?\s*/', '', $rest);
        $lines = [];

        if (preg_match('~\A/\*+(.*?)\*/~s', $rest, $match) === 1) {
            foreach (explode("\n", $match[1]) as $line) {
                $lines[] = trim((string) preg_replace('/^\s*\*+ ?/', '', $line));
            }
        } else {
            // "#[" starts an attribute, not a comment.
            while (preg_match('~\A[ \t]*(?://|#(?!\[))([^\n]*)(?:\n|\z)~', $rest, $match) === 1) {
                $lines[] = trim($match[1]);
                $rest = substr($rest, strlen($match[0]));
            }
        }

        $lines = array_values(array_filter(
            $lines,
            static fn (string $line): bool => $line !== '' && $line[0] !== '@',
        ));

        if ($lines === []) {
            return [null, ''];
        }

        $title = mb_scrub((string) array_shift($lines), 'UTF-8');
        $description = mb_scrub(implode(' ', $lines), 'UTF-8');

        if (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION) {
            $description = rtrim(mb_substr($description, 0, self::MAX_DESCRIPTION - 1, 'UTF-8')) . '…';
        }

        return [$title, $description];
    }
}
