<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

/**
 * The folder that keeps uploaded files between the runs of a tool.
 *
 * A file gets a random name here and can be read by this user only. Nothing
 * that comes from a browser ever names a file in this folder: see Uploads.
 *
 * @internal
 */
final class UploadFolder
{
    /** How long a file is kept, in seconds: one day. */
    public const LIFETIME = 86400;

    /** The folder is looked through for old files at most this often, in seconds. */
    private const CLEAN_EVERY = 3600;

    /** The name of a stored file: random, and nothing else. */
    private const NAME = '/^[0-9a-f]{32}$/D';

    private readonly \Closure $move;

    private readonly \Closure $clock;

    /** The folder, once it is known to be there and safe to use. */
    private ?string $directory = null;

    /**
     * @param string|null                        $path       the folder. null: a folder of its own inside PHP's temp folder
     * @param (\Closure(string,string):bool)|null $move      moves a file that arrived into the folder. null: the way PHP
     *                                                       does it, which only takes a file that really was uploaded
     * @param int|null                           $fileLimit  the largest file PHP takes, in bytes. null: what php.ini says
     * @param int|null                           $bodyLimit  the largest request PHP takes, in bytes. null: what php.ini says
     * @param bool|null                          $switchedOn does PHP take uploads at all? null: what php.ini says
     * @param (\Closure():int)|null              $clock      the time. null: the real one
     * @param bool|null                          $strict     the folder has to belong to this user and be closed to everybody
     *                                                       else. null: only for the folder inside PHP's temp folder
     * @param int|null                           $countLimit how many files PHP takes in one request. null: what php.ini says
     */
    public function __construct(
        private readonly ?string $path = null,
        ?\Closure $move = null,
        private readonly ?int $fileLimit = null,
        private readonly ?int $bodyLimit = null,
        private readonly ?bool $switchedOn = null,
        ?\Closure $clock = null,
        private readonly ?bool $strict = null,
        private readonly ?int $countLimit = null,
    ) {
        $this->move = $move ?? move_uploaded_file(...);
        $this->clock = $clock ?? time(...);
    }

    /** Does PHP take uploads at all? */
    public function switchedOn(): bool
    {
        // PHP has two switches: file_uploads, and max_file_uploads set to nothing.
        return ($this->switchedOn ?? (bool) ini_get('file_uploads')) && $this->countLimit() > 0;
    }

    /**
     * How many files PHP takes in one request. It drops the others without telling
     * the script, so a page has to say the number before somebody chooses more.
     */
    public function countLimit(): int
    {
        return max(0, $this->countLimit ?? (int) ini_get('max_file_uploads'));
    }

    /**
     * The largest file that can arrive, in bytes, or null when PHP sets no limit.
     * The whole request has to fit as well, so the smaller of the two settings counts.
     */
    public function fileLimit(): ?int
    {
        $limits = array_filter(
            [$this->fileLimit ?? self::setting('upload_max_filesize'), $this->bodyLimit ?? self::setting('post_max_size')],
            static fn (int $limit): bool => $limit > 0,
        );

        return $limits === [] ? null : min($limits);
    }

    /** The largest request that can arrive, in bytes, or null when PHP sets no limit. */
    public function bodyLimit(): ?int
    {
        $limit = $this->bodyLimit ?? self::setting('post_max_size');

        return $limit > 0 ? $limit : null;
    }

    public function now(): int
    {
        return ($this->clock)();
    }

    /**
     * Moves a file that arrived into the folder.
     *
     * @return string the name it has there
     *
     * @throws \RuntimeException when the folder cannot be used or the file cannot be moved
     */
    public function keep(string $arrived): string
    {
        $directory = $this->directory();
        $name = bin2hex(random_bytes(16));
        $target = $directory . DIRECTORY_SEPARATOR . $name;

        if (!($this->move)($arrived, $target)) {
            throw new \RuntimeException(sprintf('An uploaded file could not be moved into "%s".', $directory));
        }
        // PHP gives a moved file the permissions of any new file. This one is for this user only.
        @chmod($target, 0600);
        // The age of a file counts from the moment it was taken in.
        @touch($target, $this->now());

        $this->cleanNowAndThen($directory);

        return $name;
    }

    /**
     * Where a stored file lies.
     *
     * @throws \LogicException when this is not the name of a stored file
     */
    public function path(string $name): string
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new \LogicException('This is not the name of a stored file.');
        }

        return $this->directory() . DIRECTORY_SEPARATOR . $name;
    }

    public function delete(string $name): void
    {
        if (preg_match(self::NAME, $name) === 1 && is_dir($this->location())) {
            @unlink($this->location() . DIRECTORY_SEPARATOR . $name);
        }
    }

    /**
     * Deletes the stored files that are older than a day. Sessions end without
     * telling anybody, and their files would stay for ever.
     *
     * @return int how many files were deleted
     */
    public function clean(): int
    {
        $directory = $this->location();
        if (!is_dir($directory)) {
            return 0;
        }

        $deleted = 0;
        $oldest = $this->now() - self::LIFETIME;
        foreach (scandir($directory) ?: [] as $name) {
            // Only what this class put here. Anything else in the folder is not ours.
            if (preg_match(self::NAME, $name) !== 1) {
                continue;
            }
            $file = $directory . DIRECTORY_SEPARATOR . $name;
            $changed = @filemtime($file);
            if ($changed !== false && $changed < $oldest && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function cleanNowAndThen(string $directory): void
    {
        // An empty file remembers when the folder was last looked through.
        $marker = $directory . DIRECTORY_SEPARATOR . '.cleaned';
        $last = @filemtime($marker);
        $now = $this->now();

        if ($last === false || $last < $now - self::CLEAN_EVERY || $last > $now) {
            @touch($marker, $now);
            $this->clean();
        }
    }

    private function location(): string
    {
        // One folder for each copy of the library, so that two applications on one
        // machine do not share theirs.
        return $this->path
            ?? rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'script-ui-' . substr(hash('sha256', __DIR__), 0, 12);
    }

    /**
     * The folder, made when it is not there yet.
     *
     * A temp folder is shared by everybody on the machine. Somebody else could
     * make our folder first and wait for files to arrive in it. So the folder is
     * only used when it belongs to this user and nobody else gets in.
     *
     * @throws \RuntimeException when the folder cannot be made or is not safe to use
     */
    private function directory(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $path = $this->location();
        $advice = 'Pass uploads: with a folder that only PHP may write to, outside the folder the web server serves.';

        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('The folder for uploaded files, "%s", could not be made. %s', $path, $advice));
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $mode = (int) fileperms($path) & 0777;
            $strict = $this->strict ?? $this->path === null;

            if ($strict) {
                $mine = !function_exists('posix_geteuid') || fileowner($path) === posix_geteuid();
                if (is_link($path) || !$mine || ($mode & 0077) !== 0) {
                    throw new \RuntimeException(sprintf('The folder for uploaded files, "%s", is open to other users of this machine. %s', $path, $advice));
                }
            } elseif (($mode & 0002) !== 0) {
                // Anybody could swap a file in a folder that everybody may write to.
                throw new \RuntimeException(sprintf('The folder for uploaded files, "%s", is open to other users of this machine: everybody may write to it. %s', $path, $advice));
            }
        }

        if (!is_writable($path)) {
            throw new \RuntimeException(sprintf('PHP may not write to the folder for uploaded files, "%s". %s', $path, $advice));
        }

        return $this->directory = $path;
    }

    private static function setting(string $name): int
    {
        $value = ini_get($name);

        return is_string($value) && $value !== '' ? max(0, ini_parse_quantity($value)) : 0;
    }
}
