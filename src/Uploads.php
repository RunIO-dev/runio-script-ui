<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

use Runio\ScriptUi\Session\Session;

/**
 * The uploaded files of one tool, during one run of its page.
 *
 * The session keeps the list of files: which tool and which field each belongs
 * to, what it was called and how large it is. A form names a file by a random
 * handle. The handle is not the name the file has on the disk, and it only
 * counts for the tool and the field it was made for. So a forged form can name
 * no file that its user did not upload to that very field.
 *
 * What a page shows is what its script gets. Two tabs that show different
 * files each get their own, because each form names the files of its page.
 *
 * @internal
 */
final class Uploads
{
    /** How many files one field holds at most. See room(). */
    public const MAX_FILES = 20;

    /** How many files one session keeps. When more arrive, the oldest are deleted. */
    private const MAX_KEPT = 200;

    /** How many fields with files of one form are kept between page views. No page has more. */
    private const MAX_FIELDS = 50;

    private const HANDLE = '/^[0-9a-f]{32}$/D';

    /** Files that are older than a day are looked for once in every request. */
    private bool $expired = false;

    /**
     * @var array<string,array{file: string, bytes: int, sha256: string}>
     *      what the log says about the files that were forgotten in this run, by handle
     */
    private array $forgotten = [];

    /**
     * @param string                 $stateKey what this tool keeps in the session is filed under this
     * @param array<array-key,mixed> $files    the files of this request, the way PHP hands them over
     */
    public function __construct(
        private readonly UploadFolder $folder,
        private readonly Session $session,
        private readonly string $stateKey,
        private readonly array $files = [],
    ) {
    }

    /** Does PHP take uploads at all? */
    public function switchedOn(): bool
    {
        return $this->folder->switchedOn();
    }

    /**
     * How many files one field holds: no more than PHP takes in one request,
     * because the files it does not take are dropped without a word.
     */
    public function room(): int
    {
        return min(self::MAX_FILES, $this->folder->countLimit());
    }

    /** The largest file that can arrive, in bytes, or null when PHP sets no limit. */
    public function limit(): ?int
    {
        return $this->folder->fileLimit();
    }

    /**
     * The files that arrived for one field in this request.
     *
     * PHP hands over one file as texts and numbers, and several files as lists of
     * them. Anything in another shape is not read: a forged request can ask for
     * shapes that no file field sends.
     *
     * @return list<array{name: string, arrived: string, error: int}> the name the user's computer gave,
     *                                                                where the file lies now, and PHP's error code
     */
    public function posted(string $key): array
    {
        $given = $this->files[$key] ?? null;
        if (!is_array($given)) {
            return [];
        }

        $names = $given['name'] ?? null;
        $places = $given['tmp_name'] ?? null;
        $errors = $given['error'] ?? null;
        if (!is_array($names)) {
            [$names, $places, $errors] = [[$names], [$places], [$errors]];
        }

        $files = [];
        foreach ($names as $index => $name) {
            $place = is_array($places) ? ($places[$index] ?? null) : null;
            $error = is_array($errors) ? ($errors[$index] ?? null) : null;

            // "No file" is what an empty field sends.
            if (is_string($name) && is_string($place) && is_int($error) && $error !== UPLOAD_ERR_NO_FILE) {
                $files[] = ['name' => $name, 'arrived' => $place, 'error' => $error];
            }
        }

        return $files;
    }

    /** Did a file arrive with this request, for whichever field? */
    public function brought(): bool
    {
        foreach (array_keys($this->files) as $key) {
            if ($this->posted((string) $key) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Takes a file that arrived for a field into the folder and onto the list.
     *
     * @param string $name what the file is called, already made safe
     *
     * @return string the handle that names the file in a form
     *
     * @throws \RuntimeException when the file could not be kept
     */
    public function keep(string $key, string $arrived, string $name): string
    {
        $stored = $this->folder->keep($arrived);
        $path = $this->folder->path($stored);
        clearstatcache(true, $path);

        $handle = bin2hex(random_bytes(16));
        $all = $this->all();
        $all[$this->stateKey][$handle] = [
            'field' => $key,
            'stored' => $stored,
            'name' => $name,
            'size' => (int) filesize($path),
            'at' => $this->folder->now(),
        ];

        // Tabs and tools add up. A session never keeps more than this.
        $count = array_sum(array_map('count', $all));
        while ($count > self::MAX_KEPT) {
            [$oldestState, $oldestHandle] = [null, null];
            foreach ($all as $stateKey => $entries) {
                foreach ($entries as $candidate => $entry) {
                    if ($candidate !== $handle && ($oldestHandle === null || $entry['at'] < $all[$oldestState][$oldestHandle]['at'])) {
                        [$oldestState, $oldestHandle] = [$stateKey, $candidate];
                    }
                }
            }
            if ($oldestHandle === null) {
                break;
            }
            $this->folder->delete($all[$oldestState][$oldestHandle]['stored']);
            unset($all[$oldestState][$oldestHandle]);
            $count--;
        }

        $this->save($all);

        return $handle;
    }

    /**
     * The file a handle names, when it is a file of this field of this tool and
     * still there.
     *
     * @return array{name: string, size: int, path: string}|null
     */
    public function find(string $key, string $handle): ?array
    {
        $entry = $this->all()[$this->stateKey][$handle] ?? null;
        if ($entry === null || $entry['field'] !== $key) {
            return null;
        }

        $path = $this->folder->path($entry['stored']);
        if (!is_file($path)) {
            // Somebody cleaned the folder. The list follows.
            $this->drop($handle);

            return null;
        }

        return ['name' => $entry['name'], 'size' => $entry['size'], 'path' => $path];
    }

    /** Deletes a file of this tool and takes it off the list. */
    public function drop(string $handle): void
    {
        $all = $this->all();
        $entry = $all[$this->stateKey][$handle] ?? null;
        if ($entry === null) {
            return;
        }

        $this->folder->delete($entry['stored']);
        unset($all[$this->stateKey][$handle]);
        $this->save($all);
    }

    /**
     * Deletes a file because the tool is done with it. The log of this run can
     * still say which file it was.
     */
    public function forget(string $handle): void
    {
        $described = $this->describe($handle);
        if ($described !== null) {
            $this->forgotten[$handle] = $described;
        }

        $this->drop($handle);
    }

    /**
     * What the log says about a file: its name, its size and a fingerprint of
     * its content, which proves later which file it was.
     *
     * @return array{file: string, bytes: int, sha256: string}|null
     */
    public function describe(string $handle): ?array
    {
        if (isset($this->forgotten[$handle])) {
            return $this->forgotten[$handle];
        }

        $all = $this->all();
        $entry = $all[$this->stateKey][$handle] ?? null;
        if ($entry === null) {
            return null;
        }

        if (!isset($entry['sha256'])) {
            // Worked out once. The file never changes while it lies here.
            $fingerprint = @hash_file('sha256', $this->folder->path($entry['stored']));
            if ($fingerprint === false) {
                return null;
            }
            $entry['sha256'] = $all[$this->stateKey][$handle]['sha256'] = $fingerprint;
            $this->save($all);
        }

        return ['file' => $entry['name'], 'bytes' => $entry['size'], 'sha256' => $entry['sha256']];
    }

    /**
     * After a run that went well: a file that the page showed before and the new
     * page does not show any more is deleted. That covers a file the user
     * removed, a file that was replaced, and a field that is no longer drawn.
     *
     * A file that this form never named is left alone. It may belong to another tab.
     *
     * @param array<array-key,mixed> $form  what the browser sent
     * @param array<array-key,mixed> $state what the tool keeps for the next page view
     */
    public function settle(array $form, array $state): void
    {
        foreach (array_diff(self::named($form), self::named($state)) as $handle) {
            $this->drop($handle);
        }
    }

    /** Deletes every file of this tool: its page was reset. */
    public function forgetAll(): void
    {
        $all = $this->all();
        foreach ($all[$this->stateKey] ?? [] as $entry) {
            $this->folder->delete($entry['stored']);
        }
        unset($all[$this->stateKey]);
        $this->save($all);
    }

    /**
     * The part of a submitted form that names files and may be kept in the
     * session. It is checked again when the next run reads it.
     *
     * @param array<array-key,mixed> $in
     *
     * @return array{_file?: array<array-key,list<string>>}
     */
    public static function keepable(array $in): array
    {
        $keep = [];

        foreach (is_array($in['_file'] ?? null) ? $in['_file'] : [] as $key => $handles) {
            if (!Keys::isValid((string) $key) || count($keep) >= self::MAX_FIELDS) {
                continue;
            }

            $kept = [];
            foreach (is_array($handles) ? $handles : [] as $handle) {
                if (is_string($handle) && preg_match(self::HANDLE, $handle) === 1 && count($kept) < self::MAX_FILES) {
                    $kept[] = $handle;
                }
            }
            if ($kept !== []) {
                $keep[$key] = $kept;
            }
        }

        return $keep === [] ? [] : ['_file' => $keep];
    }

    /**
     * Every handle a form or a saved state names, whichever field it names it for.
     *
     * @param array<array-key,mixed> $bag
     *
     * @return list<string>
     */
    private static function named(array $bag): array
    {
        $named = [];
        foreach (self::keepable($bag)['_file'] ?? [] as $handles) {
            foreach ($handles as $handle) {
                $named[] = $handle;
            }
        }

        return $named;
    }

    /**
     * The list of files in the session, without what is older than a day and
     * without anything that does not look like an entry of this list.
     *
     * @return array<string,array<string,array{field: string, stored: string, name: string, size: int, at: int, sha256?: string}>>
     *         what a tool keeps is filed under => handle => the file
     */
    private function all(): array
    {
        $saved = $this->session->get('uploads');
        $saved = is_array($saved) ? $saved : [];
        if ($this->expired) {
            /** @var array<string,array<string,array{field: string, stored: string, name: string, size: int, at: int, sha256?: string}>> $saved */
            return $saved;
        }
        $this->expired = true;

        $all = [];
        $changed = false;
        $oldest = $this->folder->now() - UploadFolder::LIFETIME;

        foreach ($saved as $stateKey => $entries) {
            foreach (is_array($entries) ? $entries : [] as $handle => $entry) {
                $sound = is_array($entry)
                    && is_string($entry['field'] ?? null)
                    && is_string($entry['stored'] ?? null)
                    && preg_match(self::HANDLE, $entry['stored']) === 1
                    && is_string($entry['name'] ?? null)
                    && is_int($entry['size'] ?? null)
                    && is_int($entry['at'] ?? null)
                    && (!isset($entry['sha256']) || is_string($entry['sha256']))
                    && preg_match(self::HANDLE, (string) $handle) === 1;

                if ($sound && $entry['at'] >= $oldest) {
                    $all[(string) $stateKey][(string) $handle] = $entry;

                    continue;
                }

                if ($sound) {
                    // Older than a day.
                    $this->folder->delete($entry['stored']);
                }
                $changed = true;
            }
        }

        if ($changed) {
            $this->save($all);
        }

        return $all;
    }

    /** @param array<string,array<string,array<string,mixed>>> $all */
    private function save(array $all): void
    {
        $all = array_filter($all);

        if ($all === []) {
            $this->session->remove('uploads');
        } else {
            $this->session->set('uploads', $all);
        }
    }
}
