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
     * The copy is for the PHP user only, as the upload was. Call chmod() on it
     * when somebody else has to read it, such as a web server that runs as
     * another user.
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

        // The copy is written next to its place and put there when it is complete.
        // A file that is replaced is then never half written. The name is random,
        // so nobody can put a link where the copy is written.
        $part = $path . '.' . bin2hex(random_bytes(6)) . '.part';
        $failed = sprintf('"%s" could not be written.', $path);

        try {
            $to = @fopen($part, 'xb');
            if ($to === false) {
                throw new \RuntimeException($failed);
            }

            @chmod($part, 0600);
            $copied = stream_copy_to_stream($from, $to);
            if (!fclose($to) || $copied === false) {
                throw new \RuntimeException($failed);
            }

            if ($overwrite) {
                // Moved onto the target in one step. A link at the target is replaced, not followed.
                if (!@rename($part, $path)) {
                    throw new \RuntimeException($failed);
                }
            } elseif (@\link($part, $path)) {
                // PHP's own link(): this library has a function of that name too.
                // A second name for the copy, made in one step. That fails when anything
                // is at the target already, also a link that points nowhere. Opening
                // the target for writing would follow such a link.
                @unlink($part);
            } elseif (file_exists($path) || is_link($path)) {
                throw new \RuntimeException(sprintf('"%s" is already there. Pass overwrite: true to replace it.', $path));
            } elseif (!@rename($part, $path)) {
                // Some file systems cannot give a file a second name. There the place
                // was looked at first and the copy moved after.
                throw new \RuntimeException($failed);
            }
        } catch (\RuntimeException $error) {
            @unlink($part);

            throw $error;
        } finally {
            fclose($from);
        }
    }

    /**
     * Deletes the file now and empties its field. Call it when the tool is done
     * with the file, after an import for example: the file would otherwise wait
     * in its field, ready to be imported a second time.
     *
     * The file is gone at once and stays gone, also when the run fails further
     * down. Whatever was done with it has happened by then.
     */
    public function forget(): void
    {
        ($this->forget)();
    }
}
