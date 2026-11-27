<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * A file that a user uploaded with file_input().
 *
 * The file lies in a folder of the server that only PHP reads. It stays until
 * the user removes it, the tool forgets it or a day has passed. Copy it with
 * saveTo() when it has to stay for longer.
 */
final class Upload
{
    /**
     * @internal Tools get their uploads from file_input().
     *
     * @param string          $name   what the file was called on the user's computer, made safe: it never names a folder.
     *                                Its ending decides what a computer does with the file, so do not trust it
     * @param int             $size   in bytes
     * @param string          $path   where the file lies now. It is not the name the user gave it
     * @param \Closure():void $forget
     */
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        public readonly string $path,
        private readonly \Closure $forget,
    ) {
    }

    /**
     * Everything that is in the file, as one text. For a large file read from path
     * with fopen() or SplFileObject instead.
     *
     * @throws \RuntimeException when the file is no longer there
     */
    public function contents(): string
    {
        $contents = @file_get_contents($this->path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('The uploaded file "%s" is no longer there.', $this->name));
        }

        return $contents;
    }

    /**
     * Copies the file to a place where it stays.
     *
     * The name of the new file is yours to choose. When you build it from the
     * name the user gave, remember that a ".php" in a folder the web server
     * serves is a program.
     *
     * @param bool $overwrite replace a file that is already there. Without it that is an error
     *
     * @throws \RuntimeException when the copy could not be made
     */
    public function saveTo(string $path, bool $overwrite = false): void
    {
        $from = @fopen($this->path, 'rb');
        if ($from === false) {
            throw new \RuntimeException(sprintf('The uploaded file "%s" is no longer there.', $this->name));
        }

        // "x" makes the file and fails when it is already there, in one step.
        // Asking first and writing after would leave a gap between the two.
        $to = @fopen($path, $overwrite ? 'wb' : 'xb');
        if ($to === false) {
            fclose($from);

            throw new \RuntimeException(
                !$overwrite && file_exists($path)
                    ? sprintf('"%s" is already there. Pass overwrite: true to replace it.', $path)
                    : sprintf('"%s" could not be written.', $path),
            );
        }

        $copied = stream_copy_to_stream($from, $to);
        fclose($from);
        if (!fclose($to) || $copied === false) {
            @unlink($path);

            throw new \RuntimeException(sprintf('"%s" could not be written.', $path));
        }
    }

    /**
     * Deletes the file now and empties its field. Call it when the tool is done
     * with the file, after an import for example: the file would otherwise wait
     * in its field, ready to be imported a second time.
     */
    public function forget(): void
    {
        ($this->forget)();
    }
}
