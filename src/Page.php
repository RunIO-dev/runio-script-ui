<?php

declare(strict_types=1);

namespace Runio\ScriptUi;

use Runio\ScriptUi\Signal\Rerun;
use Runio\ScriptUi\Signal\Stop;

/**
 * One run of one tool. It reads what the browser sent, collects what the script
 * draws, and remembers what has to be kept for the next run.
 *
 * Where the values come from ("the bag"): on a form submission it is the form,
 * on a plain page view it is what the last submission saved. Every input reads
 * its value from the bag with the same checks, so a saved value is trusted
 * exactly as little as a submitted one.
 *
 * @internal Tools call the functions in functions.php, not this class.
 */
final class Page
{
    /** Longer values are used for this run but not kept in the session. */
    private const MAX_SAVED_BYTES = 8192;

    private const MAX_SAVED_FIELDS = 500;

    private const BUTTON_STYLES = ['default', 'primary', 'danger'];

    private const CALLOUTS = ['success', 'info', 'warning', 'error'];

    /** Up to this many options of radio() or multiselect() stand in one column. More are laid out in several. */
    private const MANY_CHOICES = 8;

    /** Said when a column, an expander or a tab got the name of a function where the function belongs. */
    private const NOT_A_NAME = 'A name in quotes is not run. Pass the function itself: fn () => ..., or name(...).';

    /** What this tool saves in the session is filed under this. Usually the tool id. */
    public readonly string $stateKey;

    /** The button the user pressed, or '' when this run was not started by a button. */
    private readonly string $click;

    /** @var array<array-key,mixed> what the next page view starts from */
    private array $out = [];

    /** @var list<list<string>> pieces of HTML; columns() pushes a level */
    private array $buffers = [[]];

    /** @var array<array-key,true> keys already taken on this page */
    private array $used = [];

    /** @var array<array-key,true> fields of this page that a link filled in */
    private array $consumed = [];

    private bool $tookOver = false;

    /** @var array<array-key,mixed> key => the value the script received, for the audit log */
    private array $values = [];

    /** @var list<array{kind: string, text: string}> messages shown after a button was pressed */
    private array $messages = [];

    private ?string $title = null;

    /**
     * @var array{key: string, label: string, confirmed: bool, file: ?string}|null
     *      the button that returned true, or the download that was asked for (then with the name of its file)
     */
    private ?array $action = null;

    /** The file that is sent instead of the page. */
    private ?Download $download = null;

    private bool $rerun = false;

    private ?\Throwable $failure = null;

    private ?int $bufferLevel = null;

    private ?Texts $texts = null;

    /**
     * @var array<array-key,array{label: string, help: ?string, multiple: bool, endings: list<string>, limit: ?int,
     *                            errors: list<array{string, string}>, held: list<string>}>
     *      the file fields of this page by key: what limits them, what went wrong (the id of a text and the
     *      name of the file) and the handles of the files they hold
     */
    private array $fileFields = [];

    /** Marks the places where the file fields go. Random, so nothing a tool prints can be taken for one. */
    private ?string $mark = null;

    /**
     * @param array<array-key,mixed> $in      the bag this run reads from
     * @param array<array-key,mixed> $carried  messages kept from the run that called rerun()
     * @param bool                   $fragment the page's own script asked: answer with the content only
     * @param array<array-key,true>  $linked   names in the bag that came from the address ("?customer-email=...")
     * @param string|null            $setLanguage the language of the built-in texts, when one is set for all pages
     * @param array<array-key,mixed> $ownTexts built-in texts the application replaced: language => (id => text)
     * @param bool                   $inputsChanged the user changed an input of the tool since the page was drawn,
     *                                              or a link filled one in
     * @param string                 $secret   a secret of the session. No page and no form ever holds it
     * @param Uploads|null           $uploads  the uploaded files of this tool
     */
    public function __construct(
        public readonly string $toolId,
        public readonly bool $isPost,
        private readonly array $in = [],
        public readonly ?string $user = null,
        public readonly bool $debug = false,
        public readonly string $selfUrl = '/',
        public readonly ?string $homeUrl = null,
        private readonly array $carried = [],
        public readonly bool $fragment = false,
        ?string $stateKey = null,
        private readonly array $linked = [],
        private readonly ?string $setLanguage = null,
        private readonly array $ownTexts = [],
        private readonly bool $inputsChanged = false,
        private readonly string $secret = '',
        private readonly ?Uploads $uploads = null,
    ) {
        $this->stateKey = $stateKey ?? $toolId;

        // A button only counts on a form submission, which has passed the token check.
        $click = $isPost ? ($in['_click'] ?? null) : null;
        $this->click = is_string($click) ? $click : '';
    }

    // ---------------------------------------------------------------- display

    public function title(string $text): void
    {
        $this->title ??= $text;
        $this->add('<h1 class="sui-title" dir="auto">' . Text::e($text) . '</h1>');
    }

    public function heading(string $text): void
    {
        $this->add('<h2 class="sui-heading" dir="auto">' . Text::e($text) . '</h2>');
    }

    public function note(string $text): void
    {
        $this->add('<p class="sui-note" dir="auto">' . Text::lines($text) . '</p>');
    }

    public function code(string $text): void
    {
        $this->add('<pre class="sui-code" dir="ltr"><code>' . Text::e($text) . '</code></pre>');
    }

    public function json(mixed $value): void
    {
        $this->add('<pre class="sui-json" dir="ltr"><code>' . Text::e(Text::json($value)) . '</code></pre>');
    }

    public function divider(): void
    {
        $this->add('<hr class="sui-divider">');
    }

    public function link(string $label, string $url, bool $newTab): void
    {
        $url = trim($url);

        // Browsers ignore tabs and line breaks inside an address, so "java<tab>script:"
        // would still run. Refuse control characters outright.
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new \InvalidArgumentException('link() needs a web address without control characters.');
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $match) === 1
            && !in_array(strtolower($match[1]), ['http', 'https', 'mailto'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'link() only accepts http, https and mailto addresses or a path on this site, not "%s:".',
                $match[1],
            ));
        }

        $this->add(
            '<p class="sui-link-row"><a class="sui-link" href="' . Text::e($url) . '"'
            . ($newTab ? ' target="_blank"' : '')
            . ' rel="noopener noreferrer" dir="auto">' . Text::e($label) . '</a></p>',
        );
    }

    public function metric(string $label, string|int|float $value, string|int|float|null $delta, bool $inverse): void
    {
        // <bdi> keeps a number in its own order while the tile follows the direction of the page.
        $html = '<div class="sui-metric"><div class="sui-metric-label">' . Text::e($label) . '</div>'
            . '<div class="sui-metric-value"><bdi>' . Text::e((string) $value) . '</bdi></div>';

        if ($delta !== null && $delta !== '') {
            if (is_string($delta)) {
                $text = $delta;
                $first = ltrim($delta);
                $direction = match (true) {
                    str_starts_with($first, '+') => 1,
                    str_starts_with($first, '-'), str_starts_with($first, '−') => -1,
                    is_numeric($first) => (float) $first <=> 0.0,
                    default => 0,
                };
            } else {
                $direction = $delta <=> 0;
                $text = ($delta > 0 ? '+' : '') . $delta;
            }

            // The arrow comes from CSS, so direction never depends on colour alone.
            $class = match ($direction) {
                1 => 'sui-up ' . ($inverse ? 'sui-bad' : 'sui-good'),
                -1 => 'sui-down ' . ($inverse ? 'sui-good' : 'sui-bad'),
                default => 'sui-flat',
            };
            $html .= '<div class="sui-metric-delta ' . $class . '"><bdi dir="ltr">' . Text::e($text) . '</bdi></div>';
        }

        $this->add($html . '</div>');
    }

    public function callout(string $kind, string $text): void
    {
        // What is said after a button was pressed is the result of that action.
        // rerun() carries it over to the reloaded page.
        if ($this->action !== null) {
            $this->messages[] = ['kind' => $kind, 'text' => $text];
        }

        $this->add(self::calloutHtml($kind, Text::lines($text)));
    }

    // ----------------------------------------------------------------- inputs

    public function text(string $label, string $default, ?string $placeholder, ?string $help, ?string $key): string
    {
        $key = $this->claim($key, $label);
        $raw = $this->submitted($key);
        $value = $raw === null ? $default : self::oneLine($raw);
        $this->remember($key, $value, $value);

        $this->add($this->field(
            $key,
            $label,
            $help,
            '<input class="sui-input" type="text" id="' . Text::e('f-' . $key) . '" name="' . Text::e($key) . '" value="' . Text::e($value) . '"'
            . ($placeholder === null ? '' : ' placeholder="' . Text::e($placeholder) . '"')
            . ' dir="auto">',
        ));

        return $value;
    }

    public function textarea(string $label, string $default, int $rows, ?string $placeholder, ?string $help, ?string $key): string
    {
        $key = $this->claim($key, $label);
        $raw = $this->submitted($key);
        $value = $raw === null ? $default : self::manyLines($raw);
        $this->remember($key, $value, $value);

        // A line break right after the opening tag is dropped by browsers, so one is
        // always written. A value that starts with a line break then keeps it.
        $this->add($this->field(
            $key,
            $label,
            $help,
            '<textarea class="sui-input" id="' . Text::e('f-' . $key) . '" name="' . Text::e($key) . '" rows="' . max(1, min(40, $rows)) . '"'
            . ($placeholder === null ? '' : ' placeholder="' . Text::e($placeholder) . '"')
            . ' dir="auto">' . "\n" . Text::e($value) . '</textarea>',
        ));

        return $value;
    }

    public function number(
        string $label,
        int|float|null $default,
        int|float|null $min,
        int|float|null $max,
        int|float|null $step,
        ?string $help,
        ?string $key,
    ): int|float|null {
        $key = $this->claim($key, $label);
        $raw = $this->submitted($key);

        $value = match (true) {
            $raw === null => $default,
            trim($raw) === '' => null,
            default => self::parseNumber($raw) ?? $default,
        };

        if ($value !== null) {
            // A whole-number step asks for whole numbers.
            if (is_int($step) && is_float($value) && abs($value) < PHP_INT_MAX) {
                $value = (int) round($value);
            }
            if ($min !== null && $value < $min) {
                $value = $min;
            }
            if ($max !== null && $value > $max) {
                $value = $max;
            }
            // A whole number is an integer, however it was written ("1e3", "12.0").
            if (is_float($value) && floor($value) === $value && abs($value) < 9007199254740992.0) {
                $value = (int) $value;
            }
        }

        $wire = $value === null ? '' : Text::exactNumber($value);
        $this->remember($key, $wire, $value);

        $this->add($this->field(
            $key,
            $label,
            $help,
            '<input class="sui-input sui-input-number" type="number" inputmode="decimal" id="' . Text::e('f-' . $key) . '" name="' . Text::e($key) . '" value="' . Text::e($wire) . '"'
            . ($min === null ? '' : ' min="' . Text::e(Text::exactNumber($min)) . '"')
            . ($max === null ? '' : ' max="' . Text::e(Text::exactNumber($max)) . '"')
            . ' step="' . ($step === null ? 'any' : Text::e(Text::exactNumber($step))) . '" dir="ltr">',
        ));

        return $value;
    }

    /**
     * @param array<array-key,mixed> $options a list of values, or value => label
     * @param bool|null              $byKey   true: the keys are the values. false: the items are. null: a list is a list
     */
    public function select(string $label, array $options, mixed $default, ?string $help, ?string $key, ?bool $byKey): mixed
    {
        $key = $this->claim($key, $label);
        $isList = $byKey === null ? array_is_list($options) : !$byKey;
        $choices = self::choices($options, $isList, 'select');
        $id = Text::e('f-' . $key);

        if ($byKey === null && $isList) {
            self::assertNoPosition('select', $label, $options, $choices, $default);
        }

        if ($choices === []) {
            $this->values[$key] = null;
            $this->add($this->field($key, $label, $help, '<select class="sui-input" id="' . $id . '" name="' . Text::e($key) . '" disabled></select>'));

            return null;
        }

        $chosen = $this->chosen($key, $choices, $default);

        $html = '<select class="sui-input" id="' . $id . '" name="' . Text::e($key) . '" dir="auto">';
        foreach ($choices as $index => $choice) {
            $html .= '<option value="' . Text::e($choice['wire']) . '"' . ($index === $chosen ? ' selected' : '') . '>'
                . Text::e($choice['label']) . '</option>';
        }

        $value = $choices[$chosen]['value'];
        $this->remember($key, $choices[$chosen]['wire'], $value);
        $this->add($this->field($key, $label, $help, $html . '</select>'));

        return $value;
    }

    /**
     * @param array<array-key,mixed> $options a list of values, or value => label
     * @param bool|null              $byKey   see select()
     */
    public function radio(string $label, array $options, mixed $default, bool $horizontal, ?string $help, ?string $key, ?bool $byKey): mixed
    {
        $key = $this->claim($key, $label);
        $isList = $byKey === null ? array_is_list($options) : !$byKey;
        $choices = self::choices($options, $isList, 'radio');

        if ($byKey === null && $isList) {
            self::assertNoPosition('radio', $label, $options, $choices, $default);
        }

        if ($choices === []) {
            $this->values[$key] = null;
            $this->add($this->choicesHtml($key, $label, $help, $horizontal, 'radio', [], []));

            return null;
        }

        $chosen = $this->chosen($key, $choices, $default);
        $value = $choices[$chosen]['value'];
        $this->remember($key, $choices[$chosen]['wire'], $value);
        $this->add($this->choicesHtml($key, $label, $help, $horizontal, 'radio', $choices, [$chosen => true]));

        return $value;
    }

    /**
     * @param array<array-key,mixed> $options a list of values, or value => label
     * @param array<array-key,mixed> $default the options that are ticked until the user says otherwise
     * @param bool|null              $byKey   see select()
     *
     * @return list<mixed> the ticked options, in the order of the options
     */
    public function multiselect(string $label, array $options, array $default, bool $horizontal, ?string $help, ?string $key, ?bool $byKey): array
    {
        $key = $this->claim($key, $label);
        $isList = $byKey === null ? array_is_list($options) : !$byKey;
        $choices = self::choices($options, $isList, 'multiselect');

        $wanted = [];
        foreach ($default as $item) {
            if ($byKey === null && $isList) {
                self::assertNoPosition('multiselect', $label, $options, $choices, $item);
            }
            if (is_string($item) || is_int($item) || is_float($item)) {
                $wanted[] = (string) $item;
            }
        }

        if ($choices === []) {
            // Nobody could tick anything, so nothing is remembered: when options
            // arrive, the field starts from its default.
            $this->values[$key] = [];
            $this->add($this->choicesHtml($key, $label, $help, $horizontal, 'checkbox', [], []));

            return [];
        }

        // A browser sends nothing for a field in which no box is ticked. The "_seen"
        // marker tells that apart from a field that was not on the page at all.
        $sent = $this->submittedList($key) ?? ($this->seen($key) ? [] : $wanted);
        $sent = array_fill_keys(array_map(self::wire(...), $sent), true);

        // What was sent only counts where it is one of the options.
        $ticked = $wires = $values = [];
        foreach ($choices as $index => $choice) {
            if (isset($sent[$choice['wire']])) {
                $ticked[$index] = true;
                $wires[] = $choice['wire'];
                $values[] = $choice['value'];
            }
        }

        $this->values[$key] = $values;
        // Like a text that is too long to be remembered: the next page view then
        // starts from the default, and not from "nothing ticked". (Until then every
        // form counts as a changed input, because nothing was saved to compare it
        // with. A table only minds when its rows are others as well.)
        if (array_sum(array_map(strlen(...), $wires)) <= self::MAX_SAVED_BYTES) {
            $this->out['_seen'][] = $key;
            if ($wires !== []) {
                $this->out[$key] = $wires;
            }
        }

        $this->add($this->choicesHtml($key, $label, $help, $horizontal, 'checkbox', $choices, $ticked));

        return $values;
    }

    public function checkbox(string $label, bool $default, ?string $help, ?string $key): bool
    {
        $key = $this->claim($key, $label);
        $raw = $this->submitted($key);

        // A browser sends nothing for an unticked box. The "_seen" marker tells an
        // unticked box apart from a box that was not on the page at all.
        $value = match (true) {
            $raw !== null => in_array(strtolower(trim($raw)), ['1', 'on', 'true', 'yes'], true),
            $this->seen($key) => false,
            default => $default,
        };

        $this->out['_seen'][] = $key;
        if ($value) {
            $this->out[$key] = '1';
        }
        $this->values[$key] = $value;

        $this->add(
            '<div class="sui-field sui-field-check"><label class="sui-check">'
            . '<input type="checkbox" id="' . Text::e('f-' . $key) . '" name="' . Text::e($key) . '" value="1"' . ($value ? ' checked' : '') . '>'
            . '<span dir="auto">' . Text::e($label) . '</span></label>'
            . '<input type="hidden" name="_seen[]" value="' . Text::e($key) . '">'
            . ($help === null ? '' : '<p class="sui-help" dir="auto">' . Text::e($help) . '</p>')
            . '</div>',
        );

        return $value;
    }

    public function date(
        string $label,
        string|\DateTimeInterface|null $default,
        string|\DateTimeInterface|null $min,
        string|\DateTimeInterface|null $max,
        ?string $help,
        ?string $key,
    ): ?string {
        $key = $this->claim($key, $label);
        $default = self::dateArgument($default);
        $min = self::dateArgument($min);
        $max = self::dateArgument($max);
        $raw = $this->submitted($key);

        $value = match (true) {
            $raw === null => $default,
            trim($raw) === '' => null,
            default => self::validDate(trim($raw)) ?? $default,
        };

        // Dates written as YYYY-MM-DD sort the same as text and as dates.
        if ($value !== null && $min !== null && $value < $min) {
            $value = $min;
        }
        if ($value !== null && $max !== null && $value > $max) {
            $value = $max;
        }

        $this->remember($key, $value ?? '', $value);

        $this->add($this->field(
            $key,
            $label,
            $help,
            '<input class="sui-input sui-input-date" type="date" id="' . Text::e('f-' . $key) . '" name="' . Text::e($key) . '" value="' . Text::e($value ?? '') . '"'
            . ($min === null ? '' : ' min="' . $min . '"')
            . ($max === null ? '' : ' max="' . $max . '"')
            . ' dir="ltr">',
        ));

        return $value;
    }

    /**
     * @param array<array-key,mixed>|null $types endings of file names, such as "csv"
     *
     * @return Upload|list<Upload>|null
     */
    public function fileInput(string $label, ?array $types, bool $multiple, ?int $maxSize, ?string $help, ?string $key): Upload|array|null
    {
        $key = $this->claim($key, $label);
        $uploads = $this->uploads();

        if (!$uploads->switchedOn()) {
            throw new \LogicException(
                'file_input() needs PHP to take uploads, and they are switched off. '
                . 'In php.ini set file_uploads = On, and max_file_uploads to 1 or more.',
            );
        }
        if ($maxSize !== null && $maxSize < 1) {
            throw new \InvalidArgumentException('file_input(): maxSize is a number of bytes and has to be at least 1.');
        }
        // A folder that cannot be used is a mistake in the setup. It shows on the
        // first page view, to whoever sets the tool up, and not on the first upload.
        $uploads->ready();

        $endings = self::endings($types);
        // PHP has limits of its own, and a file never gets past them.
        $limit = match (true) {
            $maxSize === null => $uploads->limit(),
            $uploads->limit() === null => $maxSize,
            default => min($maxSize, $uploads->limit()),
        };
        $room = $multiple ? $uploads->room() : 1;
        $errors = [];

        // The files the page showed: the form names them. Only a file that was
        // uploaded to this field of this tool counts, and only while it is there.
        $held = [];
        $named = is_array($this->in['_file'] ?? null) ? ($this->in['_file'][$key] ?? null) : null;
        foreach (is_array($named) ? $named : [] as $handle) {
            if (!is_string($handle) || count($held) >= $room) {
                continue;
            }
            if ($uploads->find($key, $handle) === null) {
                // Removed in another tab, or kept for longer than a day.
                $errors['file.gone'] = ['file.gone', ''];
            } else {
                $held[$handle] = true;
            }
        }

        if (preg_match('/^' . preg_quote($key, '/') . ':remove:([0-9a-f]{32})$/D', $this->click, $match) === 1) {
            // The file itself is deleted when this run has ended well. See Uploads::settle().
            unset($held[$match[1]]);
        }

        $replaced = false;
        foreach ($uploads->posted($key) as $file) {
            $name = Download::name($file['name'], 'upload');

            if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $errors[] = ['file.too_large', $name];

                continue;
            }
            $size = $file['error'] === UPLOAD_ERR_OK ? @filesize($file['arrived']) : false;
            if ($size === false) {
                // Cut off on the way, or the server could not write it. The user can only try again.
                error_log(sprintf('Script UI: the file "%s" did not arrive in the tool "%s" (PHP upload error %d).', $name, $this->toolId, $file['error']));
                $errors[] = ['file.failed', $name];

                continue;
            }
            if ($limit !== null && $size > $limit) {
                $errors[] = ['file.too_large', $name];

                continue;
            }
            if ($endings !== [] && !in_array(Download::ending($name), $endings, true)) {
                $errors[] = ['file.wrong_type', $name];

                continue;
            }

            if (!$multiple) {
                // A field for one file: the new file takes the place of the old one.
                if (!$replaced) {
                    $held = [$uploads->keep($key, $file['arrived'], $name) => true];
                    $replaced = true;
                }

                continue;
            }
            if (count($held) >= $room) {
                $errors['file.too_many'] = ['file.too_many', ''];

                continue;
            }
            $held[$uploads->keep($key, $file['arrived'], $name)] = true;
        }

        $handles = array_map('strval', array_keys($held));
        $this->fileFields[$key] = [
            'label' => $label,
            'help' => $help,
            'multiple' => $multiple,
            'endings' => $endings,
            'limit' => $limit,
            'errors' => array_values($errors),
            'held' => $handles,
        ];
        $this->keepFiles($key);

        // For the log: the files this run got, also when the tool forgets them
        // afterwards. Worked out only when an entry is written.
        $this->values[$key] = static function () use ($uploads, $handles, $multiple): mixed {
            $described = [];
            foreach ($handles as $handle) {
                $file = $uploads->describe($handle);
                if ($file !== null) {
                    $described[] = $file;
                }
            }

            return $multiple ? $described : ($described[0] ?? null);
        };

        // The field is drawn when the page is finished. See fileFieldHtml().
        $this->add($this->filePlace($key));

        $files = [];
        foreach ($handles as $handle) {
            $file = $uploads->find($key, $handle);
            if ($file !== null) {
                $files[] = new Upload($file['name'], $file['size'], $file['path'], function () use ($key, $handle): void {
                    $this->forgetFile($key, $handle);
                });
            }
        }

        return $multiple ? $files : ($files[0] ?? null);
    }

    // ------------------------------------------------------------------- data

    /**
     * @param iterable<mixed>             $rows
     * @param array<array-key,mixed>|null $columns
     *
     * @return list<mixed> the selected rows, exactly as they were passed in
     */
    public function table(iterable $rows, bool $selectable, ?array $columns, ?string $key, string $rowKey, int $pageSize, bool $search, ?string $empty): array
    {
        $key = $this->claimTable($key);
        $table = Table::draw($key, $rows, $selectable, $columns, $rowKey, $pageSize, $search, $empty, $this->in, $this->click, $this->inputsChanged, $this->secret, $this->texts());

        foreach ($table->keep as $name => $value) {
            $this->out[$name][$key] = $value;
        }
        if ($table->named !== null) {
            $this->values[$key] = $table->named;
        }
        $this->add($table->html);

        return $table->picked;
    }

    /**
     * @param iterable<mixed>                   $rows
     * @param string|array<array-key,mixed>     $y    one column, a list of columns, or column => label
     */
    public function chart(iterable $rows, string $x, string|array $y, string $type, ?string $title, int $height, bool $data): void
    {
        if (!in_array($type, ['bar', 'line'], true)) {
            throw new \InvalidArgumentException(sprintf('chart() draws "bar" and "line" charts, not "%s".', $type));
        }

        $records = [];
        foreach ($rows as $row) {
            $records[] = Rows::record($row);
        }

        $series = [];
        foreach (Rows::chosen((array) $y, Rows::names($records), 'chart() needs column names for y.') as $column) {
            $series[$column['field']] = $column['label'];
        }

        if ($series === []) {
            throw new \InvalidArgumentException('chart() needs at least one column for y.');
        }
        if (count($series) > Chart::MAX_SERIES) {
            throw new \InvalidArgumentException(sprintf(
                'chart() shows at most %d series and got %d. Fold the small ones into "Other", or draw several charts.',
                Chart::MAX_SERIES,
                count($series),
            ));
        }

        $this->add(Chart::figure($records, $x, $series, $type, $title, $height, $data, $this->texts()));
    }

    // ---------------------------------------------------------------- actions

    public function button(string $label, string $style, ?string $confirm, ?string $yes, ?string $no, ?string $key): bool
    {
        if (!in_array($style, self::BUTTON_STYLES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'button() knows the styles %s, not "%s".',
                implode(', ', self::BUTTON_STYLES),
                $style,
            ));
        }

        $key = $this->claim($key, $label);
        $html = '<button type="submit" class="sui-btn sui-btn-' . $style . '" id="' . Text::e('b-' . $key) . '" name="_click" value="' . Text::e($key) . '" dir="auto">'
            . Text::e($label) . '</button>';

        if ($confirm === null) {
            $pressed = $this->click === $key;
        } else {
            // The answer names the question it belongs to. If the question changed after
            // it was shown (other rows selected, another amount), the old yes does not count.
            $yesValue = $key . ':yes:' . substr(hash('sha256', $confirm), 0, 12);
            $pressed = $this->click === $yesValue;

            if (!$pressed && ($this->click === $key || str_starts_with($this->click, $key . ':yes:'))) {
                $html .= '<div class="sui-confirm" data-confirm="' . Text::e($key) . '" role="group" aria-label="' . Text::e($this->t('confirm.title')) . '" tabindex="-1" data-sui-focus>'
                    . '<p class="sui-question" dir="auto">' . Text::lines($confirm) . '</p>'
                    . '<div class="sui-answers">'
                    // When an answer is given the question goes away. The page script then
                    // puts the keyboard focus back on the button the question belongs to.
                    . '<button type="submit" class="sui-btn sui-btn-' . ($style === 'danger' ? 'danger' : 'primary') . '" id="' . Text::e('b-' . $key . ':yes') . '" data-sui-return="' . Text::e('b-' . $key) . '" name="_click" value="' . Text::e($yesValue) . '" dir="auto">' . Text::e($yes ?? $this->t('confirm.yes')) . '</button>'
                    . '<button type="submit" class="sui-btn sui-btn-default" id="' . Text::e('b-' . $key . ':no') . '" data-sui-return="' . Text::e('b-' . $key) . '" name="_click" value="' . Text::e($key . ':no') . '" dir="auto">' . Text::e($no ?? $this->t('confirm.no')) . '</button>'
                    . '</div></div>';
            }
        }

        $this->add($html);

        if ($pressed) {
            $this->action = ['key' => $key, 'label' => $label, 'confirmed' => $confirm !== null, 'file' => null];
        }

        return $pressed;
    }

    /**
     * @param iterable<mixed>|string|\Closure $content rows, text, or a function that returns one of the two
     */
    public function download(string $label, iterable|string|\Closure $content, string $file, string $style, ?string $key): void
    {
        if (!in_array($style, self::BUTTON_STYLES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'download() knows the styles %s, not "%s".',
                implode(', ', self::BUTTON_STYLES),
                $style,
            ));
        }

        $key = $this->claim($key, $label);

        // A wrong name is a mistake in the tool. It shows on the first page view,
        // not only when somebody asks for the file.
        $name = Download::name($file);
        if (!is_string($content) && !$content instanceof \Closure) {
            Download::assertWritesRows($name);
        }

        $this->add(
            '<button type="submit" class="sui-btn sui-btn-' . $style . ' sui-btn-download" id="' . Text::e('b-' . $key) . '" name="_click" value="' . Text::e($key) . '" dir="auto">'
            . Text::e($label) . '</button>',
        );

        if ($this->click !== $key) {
            return;
        }

        // From here on this is an action. It goes to the log even when the file cannot be made.
        $this->action = ['key' => $key, 'label' => $label, 'confirmed' => false, 'file' => $name];
        $this->download = new Download($name, Download::body($content, $name));

        // The file is the answer, so the rest of the page is not needed.
        throw new Stop();
    }

    // ------------------------------------------------------------ flow, layout

    public function columns(callable ...$columns): void
    {
        foreach ($columns as $column) {
            if (!self::isFunction($column)) {
                throw new \InvalidArgumentException(
                    'columns() takes a function for every column: columns(fn () => metric(...), fn () => chart(...)). ' . self::NOT_A_NAME,
                );
            }
        }

        $cells = [];

        try {
            foreach ($columns as $column) {
                // What the script printed up to here belongs above the columns, or into
                // the column before this one. It must not slip into this one.
                $this->flushPrinted();
                $this->buffers[] = [];
                try {
                    $column();
                } finally {
                    $this->flushPrinted();
                    $cells[] = implode('', (array) array_pop($this->buffers));
                }
            }
        } finally {
            // Also when a column stopped the run or failed: what was drawn stays visible.
            $html = '<div class="sui-row">';
            foreach ($cells as $cell) {
                $html .= '<div class="sui-col">' . $cell . '</div>';
            }
            $this->add($html . '</div>');
        }
    }

    /**
     * @param callable():mixed $content draws what is inside
     */
    public function expander(string $label, callable $content, bool $open, ?string $key): void
    {
        if (!self::isFunction($content)) {
            throw new \InvalidArgumentException(
                'expander() takes a function that draws what is inside: expander(\'Details\', fn () => json($row)). ' . self::NOT_A_NAME,
            );
        }

        $key = $this->claim($key, $label);

        // The browser opens and closes the box on its own, without a request. The
        // page script writes into a hidden field how the user left it, and the next
        // run draws it that way. Anything but "1" and "0" is the way it starts.
        $sent = is_array($this->in['_open'] ?? null) ? ($this->in['_open'][$key] ?? null) : null;
        $open = match ($sent) {
            '1' => true,
            '0' => false,
            default => $open,
        };

        $claimed = count($this->used);
        $failed = true;

        $this->flushPrinted();
        $this->buffers[] = [];
        try {
            $this->part($content);
            $failed = false;
        } catch (Stop $signal) {
            // rerun() or a download: the run ends, and it did not fail.
            $failed = false;

            throw $signal;
        } finally {
            // Also when the content failed: what was drawn stays visible.
            $this->flushPrinted();
            $inside = implode('', (array) array_pop($this->buffers));

            // Without the page script the server does not hear that the box was opened.
            // A click on something inside proves that it was. And what a part has to
            // say about its own failure must not stay out of sight. A message alone
            // opens nothing: a box with a tip in it would spring open after every
            // click on the page.
            $open = $open || $failed || $this->clickedIn(array_slice($this->used, $claimed, null, true));
            $this->out['_open'][$key] = $open ? '1' : '0';

            $this->add(
                '<details class="sui-expander"' . ($open ? ' open' : '') . '>'
                . '<summary id="' . Text::e('x-' . $key) . '" dir="auto">' . Text::e($label) . '</summary>'
                . '<input type="hidden" name="' . Text::e('_open[' . $key . ']') . '" value="' . ($open ? '1' : '0') . '">'
                . '<div class="sui-expander-body sui-flow">' . $inside . '</div>'
                . '</details>',
            );
        }
    }

    /**
     * @param array<array-key,mixed> $tabs the label of each tab and the function that draws it
     */
    public function tabs(array $tabs, ?string $key): void
    {
        foreach ($tabs as $label => $content) {
            if (!self::isFunction($content)) {
                throw new \InvalidArgumentException(sprintf(
                    'tabs() takes a label and a function for every tab: [\'Orders\' => fn () => table($orders)]. The tab "%s" has no function. %s',
                    (string) $label,
                    self::NOT_A_NAME,
                ));
            }
        }

        // Tabs have no label of their own to make a key from. They are numbered like tables.
        $key = $this->claimNumbered($key, 'tabs');
        if ($tabs === []) {
            return;
        }

        $labels = array_map(strval(...), array_keys($tabs));
        $slugs = array_map(Keys::slug(...), $labels);
        $open = $this->openTab($key, $slugs);
        $panels = [];

        try {
            // Every tab runs, whichever is in view. The script gets the value of every
            // input that way, and no key and no table changes its number with the open tab.
            foreach (array_values($tabs) as $position => $content) {
                $this->flushPrinted();
                $this->buffers[] = [];
                try {
                    $this->part($content);
                } catch (Stop $signal) {
                    // rerun() or a download: the run ends, and nothing broke.
                    throw $signal;
                } catch (\Throwable $error) {
                    // The tab that broke is the one to look at.
                    $open = $position;

                    throw $error;
                } finally {
                    $this->flushPrinted();
                    $panels[] = implode('', (array) array_pop($this->buffers));
                }
            }
        } finally {
            // Also when a tab failed or ended the run: every header is there, and
            // what was drawn stays visible.
            $this->out['_tab'][$key] = $open . ':' . $slugs[$open];
            $this->add($this->tabsHtml($key, $labels, $slugs, $panels, $open));
        }
    }

    /**
     * Which tab is in view. The page script changes it without a request and
     * writes into a hidden field which one it is: its place and the key that its
     * label makes, "1:customers". Without the script a header is a button of the
     * form, and pressing it counts for more than the field.
     *
     * A tab is found again by its place and by its label. When another tab was put
     * in front of it, the label finds it. When its label changed ("Orders (3)" is
     * "Orders (4)" now), the place does.
     *
     * @param list<string> $slugs the key that the label of each tab makes
     */
    private function openTab(string $key, array $slugs): int
    {
        if (preg_match('/^' . preg_quote($key, '/') . ':tab:(0|[1-9]\d{0,2})$/D', $this->click, $pressed) === 1 && isset($slugs[(int) $pressed[1]])) {
            return (int) $pressed[1];
        }

        $sent = is_array($this->in['_tab'] ?? null) ? ($this->in['_tab'][$key] ?? null) : null;
        $named = is_string($sent) ? self::tabNamed($sent) : null;
        if ($named === null) {
            return 0;
        }

        [$position, $slug] = $named;
        if (($slugs[$position] ?? null) === $slug) {
            return $position;
        }

        $again = array_search($slug, $slugs, true);
        if ($again !== false) {
            return $again;
        }

        return isset($slugs[$position]) ? $position : 0;
    }

    /**
     * Reads what a form says about the tab that is in view: "1:customers".
     *
     * @return array{int, string}|null its place and the key of its label, or null when the text is not of that kind
     */
    private static function tabNamed(string $sent): ?array
    {
        // A place is written in one way only ("1", never "01"). The second part is
        // what Keys::slug() can make, and nothing else.
        return preg_match('/^(0|[1-9]\d{0,2}):([\p{L}\p{M}\p{N}-]{1,' . Keys::MAX_LENGTH . '})$/uD', $sent, $parts) === 1
            ? [(int) $parts[1], $parts[2]]
            : null;
    }

    /**
     * @param list<string> $labels
     * @param list<string> $slugs
     * @param list<string> $panels what each tab drew. A tab that was not reached drew nothing
     */
    private function tabsHtml(string $key, array $labels, array $slugs, array $panels, int $open): string
    {
        $headers = '';
        $bodies = '';

        foreach ($labels as $position => $label) {
            $header = Text::e('b-' . $key . ':' . $position);
            $panel = Text::e('t-' . $key . ':' . $position);
            $isOpen = $position === $open;

            // A button of the form: without the page script it sends the form, and the
            // server draws the page with this tab in view.
            $headers .= '<button type="submit" class="sui-tab" role="tab" id="' . $header . '"'
                . ' name="_click" value="' . Text::e($key . ':tab:' . $position) . '"'
                . ' aria-selected="' . ($isOpen ? 'true' : 'false') . '" aria-controls="' . $panel . '"'
                . ' data-sui-tab="' . Text::e($position . ':' . $slugs[$position]) . '" dir="auto">'
                . Text::e($label) . '</button>';

            $bodies .= '<div class="sui-tab-panel sui-flow" role="tabpanel" id="' . $panel . '" aria-labelledby="' . $header . '"'
                . ($isOpen ? '' : ' hidden') . '>' . ($panels[$position] ?? '') . '</div>';
        }

        return '<div class="sui-tabs" id="' . Text::e('t-' . $key) . '">'
            . '<div class="sui-tab-list" role="tablist">' . $headers . '</div>'
            . '<input type="hidden" name="' . Text::e('_tab[' . $key . ']') . '" value="' . Text::e($open . ':' . $slugs[$open]) . '">'
            . $bodies
            . '</div>';
    }

    /**
     * Can this draw a part of the page? A function, a method or an object that can
     * be called. Not the name of a function in quotes, although PHP would call
     * that too: a text that reaches this place from a form or from a database must
     * never be run because it happens to name a function.
     */
    private static function isFunction(mixed $content): bool
    {
        return !is_string($content) && is_callable($content);
    }

    /**
     * Draws a part of the page that can be out of sight: what is inside an
     * expander or behind a tab. stop() inside it ends the part and not the page.
     * A page that ends inside something nobody sees would end for no reason that
     * anybody could find. rerun() and a download still end the run.
     *
     * @param callable():mixed $content
     */
    private function part(callable $content): void
    {
        try {
            $content();
        } catch (Stop $signal) {
            if ($signal::class !== Stop::class || $this->download !== null) {
                throw $signal;
            }
        }
    }

    /**
     * Did the click of this run come from one of these inputs, buttons or tables?
     * A click names its key first: "save", "save:yes:1f3a", "orders:page:2".
     *
     * @param array<array-key,true> $keys
     */
    private function clickedIn(array $keys): bool
    {
        return $this->click !== '' && isset($keys[explode(':', $this->click, 2)[0]]);
    }

    public function stop(): never
    {
        throw new Stop();
    }

    public function rerun(): never
    {
        if (!$this->isPost) {
            throw new \LogicException(
                'rerun() loads the page again after a button was handled. It cannot be called on a plain page view, because the page would reload forever.',
            );
        }

        $this->rerun = true;

        throw new Rerun();
    }

    // -------------------------------------------------- for the kernel only

    /** Starts collecting what the script prints with echo, print_r or var_dump. */
    public function capture(): void
    {
        ob_start();
        $this->bufferLevel = ob_get_level();
    }

    /** Stops collecting printed output. Safe to call more than once. */
    public function release(): void
    {
        if ($this->bufferLevel === null) {
            return;
        }

        if (ob_get_level() < $this->bufferLevel && $this->failure === null) {
            // The script closed the buffer of the page to send an answer of its own,
            // a file for example. Then the page is not sent after it.
            // (After a failure a missing buffer means something else: when PHP runs
            // out of memory it throws all buffers away. The error page is still sent.)
            $this->tookOver = true;
            $this->bufferLevel = null;

            return;
        }

        // Buffers the script opened and forgot are poured into ours.
        while (ob_get_level() > $this->bufferLevel) {
            ob_end_flush();
        }
        $this->flushPrinted();
        if (ob_get_level() === $this->bufferLevel) {
            ob_end_clean();
        }

        $this->bufferLevel = null;
    }

    /** True when the script sent its own answer instead of the page. */
    public function tookOver(): bool
    {
        return $this->tookOver;
    }

    /**
     * The address of this tool without the parameters that filled in fields.
     * The form posts here and rerun() comes back here. Otherwise a value from a
     * link would be put back into its field after every run.
     */
    public function address(): string
    {
        if ($this->consumed === [] || !str_contains($this->selfUrl, '?')) {
            return $this->selfUrl;
        }

        [$path, $query] = explode('?', $this->selfUrl, 2);
        parse_str($query, $parameters);
        $kept = array_diff_key($parameters, $this->consumed);

        if (count($kept) === count($parameters)) {
            return $this->selfUrl;
        }

        return $path . ($kept === [] ? '' : '?' . http_build_query($kept));
    }

    public function fail(\Throwable $error): void
    {
        $this->failure = $error;
    }

    public function failure(): ?\Throwable
    {
        return $this->failure;
    }

    public function wantsRerun(): bool
    {
        return $this->rerun;
    }

    /** @return array{key: string, label: string, confirmed: bool, file: ?string}|null */
    public function action(): ?array
    {
        return $this->action;
    }

    /** The file to send instead of the page, when the user asked for one in this run. */
    public function fileToSend(): ?Download
    {
        return $this->download;
    }

    /** Takes the file back. The page is sent after all. */
    public function withhold(\Throwable $reason): void
    {
        $this->download = null;
        $this->failure = $reason;
    }

    /**
     * What the script got from each input, for the log.
     *
     * @return array<array-key,mixed>
     */
    public function values(): array
    {
        // A file field works out the fingerprints of its files only here, when somebody asks.
        return array_map(static fn (mixed $value): mixed => $value instanceof \Closure ? $value() : $value, $this->values);
    }

    /** Does the page have a field that takes files? Its form then has to be sent in another way. */
    public function hasFileField(): bool
    {
        return $this->fileFields !== [];
    }

    /**
     * After a run that went well: deletes the files that the page showed before
     * and does not show any more.
     *
     * What counts is what this page holds, not what was saved of it. A form that
     * is too large to be remembered still shows its files.
     */
    public function settleFiles(): void
    {
        if ($this->isPost) {
            $this->uploads?->settle($this->in, $this->state());
        }
    }

    /** @return list<array{kind: string, text: string}> */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * What to keep for the next page view.
     *
     * @return array<array-key,mixed>
     */
    public function state(): array
    {
        // After rerun() and after a download the inputs below that call were never
        // reached, so the checked values are incomplete. Keep what the form sent
        // instead. It is checked again when the next run reads it.
        if (!$this->rerun && $this->download === null) {
            return $this->out;
        }

        $state = self::keepable($this->in);
        // A file field that was reached knows better than the form: a file may
        // have arrived in this very run, or the tool may have forgotten one.
        foreach ($this->fileFields as $key => $field) {
            unset($state['_file'][$key]);
            if ($field['held'] !== []) {
                $state['_file'][$key] = $field['held'];
            }
        }
        if (($state['_file'] ?? null) === []) {
            unset($state['_file']);
        }

        return $state;
    }

    public function documentTitle(): string
    {
        return $this->title ?? Text::humanise($this->toolId);
    }

    /**
     * The language of the built-in texts: the one that is set for all pages, or
     * else the one the title is written in.
     */
    public function language(): string
    {
        return $this->setLanguage ?? ($this->title === null ? 'en' : Text::language($this->title));
    }

    /**
     * The direction of the page. The words of a page come from its script, so an
     * Arabic title turns the page whatever language is set.
     */
    public function rightToLeft(): bool
    {
        return $this->texts()->rightToLeft() || ($this->title !== null && Text::language($this->title) !== 'en');
    }

    /** The built-in texts in the language of the page. They change when title() changes that language. */
    public function texts(): Texts
    {
        $language = $this->language();
        if ($this->texts === null || $this->texts->language !== $language) {
            $this->texts = Texts::load($language, $this->ownTexts);
        }

        return $this->texts;
    }

    /** Everything the script drew, as the inside of the form. */
    public function html(): string
    {
        $html = '';

        foreach ($this->carried as $message) {
            if (is_array($message)
                && is_string($message['kind'] ?? null)
                && is_string($message['text'] ?? null)
                && in_array($message['kind'], self::CALLOUTS, true)) {
                $html .= self::calloutHtml($message['kind'], Text::lines($message['text']));
            }
        }

        foreach ($this->buffers as $buffer) {
            $html .= implode('', $buffer);
        }

        foreach (array_keys($this->fileFields) as $key) {
            $html = str_replace($this->filePlace((string) $key), $this->fileFieldHtml((string) $key), $html);
        }

        return $html . ($this->failure === null ? '' : $this->failureHtml($this->failure));
    }

    // --------------------------------------------------------------- internals

    /**
     * A built-in text in the language of the page, as plain text.
     *
     * @param array<string,string|int> $values
     */
    private function t(string $id, array $values = []): string
    {
        return $this->texts()->get($id, $values);
    }

    private function add(string $html): void
    {
        $this->flushPrinted();
        $this->buffers[array_key_last($this->buffers)][] = $html;
    }

    /** Moves what the script printed since the last element into the page, as plain text. */
    private function flushPrinted(): void
    {
        if ($this->bufferLevel === null || ob_get_level() !== $this->bufferLevel) {
            return;
        }

        $printed = (string) ob_get_contents();
        ob_clean();

        if (trim($printed) !== '') {
            $this->buffers[array_key_last($this->buffers)][] =
                '<pre class="sui-echo" dir="auto">' . Text::e(rtrim($printed)) . '</pre>';
        }
    }

    private function uploads(): Uploads
    {
        return $this->uploads ?? throw new \LogicException('This page was started without a place for uploaded files.');
    }

    /**
     * The endings that a file field takes, in small letters and without the dot.
     *
     * @param array<array-key,mixed>|null $types
     *
     * @return list<string> empty: every kind of file
     */
    private static function endings(?array $types): array
    {
        if ($types === null) {
            return [];
        }
        if ($types === []) {
            throw new \InvalidArgumentException('file_input(): types names no kind of file. Leave it out to take every kind.');
        }

        $endings = [];
        foreach ($types as $type) {
            // "csv", ".csv" and "CSV" are the same. Of "tar.gz" the last part counts, as it does in a file name.
            $ending = is_string($type) ? strtolower(trim($type)) : '';
            $dot = strrpos($ending, '.');
            $ending = $dot === false ? $ending : substr($ending, $dot + 1);

            if (preg_match('/^[a-z0-9]{1,16}$/D', $ending) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'file_input(): types are endings of file names, such as "csv" or "pdf". %s is not one.',
                    is_string($type) ? '"' . $type . '"' : get_debug_type($type),
                ));
            }
            $endings[$ending] = true;
        }

        return array_map('strval', array_keys($endings));
    }

    /** What stands in the page where a file field goes, until the page is finished. */
    private function filePlace(string $key): string
    {
        $this->mark ??= bin2hex(random_bytes(8));

        return '<!--sui-file:' . $this->mark . ':' . $key . '-->';
    }

    /** Writes into what is kept for the next page view which files a field holds. */
    private function keepFiles(string $key): void
    {
        unset($this->out['_file'][$key]);
        if ($this->fileFields[$key]['held'] !== []) {
            $this->out['_file'][$key] = $this->fileFields[$key]['held'];
        }
        if (($this->out['_file'] ?? null) === []) {
            unset($this->out['_file']);
        }
    }

    /** The tool is done with a file: it is deleted, and its field no longer shows it. */
    private function forgetFile(string $key, string $handle): void
    {
        $this->uploads()->forget($handle);

        $this->fileFields[$key]['held'] = array_values(array_diff($this->fileFields[$key]['held'], [$handle]));
        $this->keepFiles($key);
    }

    /**
     * A file field. It is drawn when the page is finished and not where the script
     * calls it: a tool typically forgets a file further down, after it has used it,
     * and the field has to be empty then.
     */
    private function fileFieldHtml(string $key): string
    {
        $field = $this->fileFields[$key];
        $uploads = $this->uploads();
        $texts = $this->texts();
        $id = 'f-' . $key;

        $kinds = implode(', ', array_map('strtoupper', $field['endings']));
        $size = $field['limit'] === null ? '' : $texts->bytes($field['limit']);
        $count = $texts->number($uploads->room());

        $files = '';
        $shown = 0;
        foreach ($field['held'] as $handle) {
            $file = $uploads->find($key, $handle);
            if ($file === null) {
                continue;
            }

            // The hidden field says which file the page shows. It is a handle, never a path.
            // When "Remove" is pressed the button goes away, and the focus moves to the field.
            // The names are what the page script reads out after a change. See announce() there.
            $files .= '<li class="sui-file">'
                . '<span class="sui-file-name" data-sui-status dir="auto">' . Text::e($file['name']) . '</span>'
                . '<span class="sui-file-size">' . Text::e($texts->bytes($file['size'])) . '</span>'
                . '<button type="submit" class="sui-link-btn" id="' . Text::e('b-' . $key . ':remove:' . $shown) . '" data-sui-return="' . Text::e($id) . '"'
                . ' name="_click" value="' . Text::e($key . ':remove:' . $handle) . '" aria-label="' . Text::e($texts->get('file.remove_label', ['name' => $file['name']])) . '">'
                . Text::e($texts->get('file.remove')) . '</button>'
                . '<input type="hidden" name="' . Text::e('_file[' . $key . '][]') . '" value="' . Text::e($handle) . '">'
                . '</li>';
            $shown++;
        }

        $note = trim(
            ($kinds === '' ? '' : $texts->get('file.types', ['types' => $kinds]))
            . ' ' . ($size === '' ? '' : $texts->get('file.limit', ['size' => $size])),
        );

        $messages = [];
        foreach ($field['errors'] as [$error, $name]) {
            $messages[] = $texts->get($error, ['name' => $name, 'size' => $size, 'types' => $kinds, 'count' => $count]);
        }

        $described = implode(' ', array_filter([$note === '' ? '' : 'l-' . $key, $messages === [] ? '' : 'e-' . $key]));

        // The page script reads the limits here and checks a file before it sends it.
        $input = '<input class="sui-input sui-input-file" type="file" id="' . Text::e($id) . '" name="' . Text::e($field['multiple'] ? $key . '[]' : $key) . '"'
            . ($field['multiple'] ? ' multiple' : '')
            . ($field['endings'] === [] ? '' : ' accept="' . Text::e('.' . implode(',.', $field['endings'])) . '"')
            . ($field['limit'] === null ? '' : ' data-sui-max="' . $field['limit'] . '" data-sui-too-large="'
                . Text::e($texts->get('file.too_large', ['name' => '{name}', 'size' => $size])) . '"')
            . ($field['multiple'] ? ' data-sui-room="' . max(0, $uploads->room() - $shown) . '" data-sui-too-many="'
                . Text::e($texts->get('file.too_many', ['count' => $count])) . '"' : '')
            // PHP throws a request away that is larger than this, form and files together.
            . ($uploads->bodyLimit() === null ? '' : ' data-sui-total="' . $uploads->bodyLimit() . '"')
            // A server in front of PHP may take less than PHP does. Nobody here knows its limit.
            . ' data-sui-refused="' . Text::e($texts->get('error.too_large_text')) . '"'
            // The connection can break while a file is on its way.
            . ' data-sui-failed="' . Text::e($texts->get('file.failed', ['name' => '{name}'])) . '"'
            . ($described === '' ? '' : ' aria-describedby="' . Text::e($described) . '"')
            . '>';

        return '<div class="sui-field sui-field-file"><label for="' . Text::e($id) . '" dir="auto">' . Text::e($field['label']) . '</label>'
            . ($files === '' ? '' : '<ul class="sui-files">' . $files . '</ul>')
            . $input
            . ($note === '' ? '' : '<p class="sui-help sui-file-limit" id="' . Text::e('l-' . $key) . '" dir="auto">' . Text::e($note) . '</p>')
            . ($messages === [] ? '' : '<p class="sui-field-error" id="' . Text::e('e-' . $key) . '" role="alert" dir="auto">' . Text::lines(implode("\n", $messages)) . '</p>')
            . ($field['help'] === null ? '' : '<p class="sui-help" dir="auto">' . Text::e($field['help']) . '</p>')
            . '</div>';
    }

    private function claim(?string $key, string $label): string
    {
        if ($key !== null) {
            Keys::assertValid($key);
            if (isset($this->used[$key])) {
                throw new \LogicException(sprintf(
                    'The key "%s" is used twice on this page. Every input and button needs its own key.',
                    $key,
                ));
            }
            $this->used[$key] = true;

            return $key;
        }

        // Numbering them (name, name-2) would follow the order on the page. An input
        // that is left out of one run would hand its key, and with it its value or
        // its click, to the next input with the same label.
        $key = Keys::slug($label);
        if (isset($this->used[$key])) {
            throw new \LogicException(sprintf(
                'Two inputs or buttons on this page are called "%s", so both would get the key "%s". '
                . 'Give each its own with key:, for example key: \'%s-2\'. '
                . 'In a loop, put the id of the row into the key: button(\'Refund\', key: \'refund-\' . $order[\'id\']).',
                $label,
                $key,
                $key,
            ));
        }
        $this->used[$key] = true;

        return $key;
    }

    /** A table has no label to make a key from. Tables are numbered in the order they are drawn. */
    private function claimTable(?string $key): string
    {
        return $this->claimNumbered($key, 'table');
    }

    /**
     * The key of something that has no label: the key the developer chose, or its
     * kind with the next free number ("table", "table-2", "tabs").
     */
    private function claimNumbered(?string $key, string $kind): string
    {
        if ($key !== null) {
            return $this->claim($key, $kind);
        }

        $candidate = $kind;
        for ($n = 2; isset($this->used[$candidate]); $n++) {
            $candidate = $kind . '-' . $n;
        }
        $this->used[$candidate] = true;

        return $candidate;
    }

    private function submitted(string $key): ?string
    {
        if (isset($this->linked[$key])) {
            // A link filled this field in. The address of the page drops the parameter.
            $this->consumed[$key] = true;
        }

        $value = $this->in[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * What the browser sent for a field that takes several values. A link may name
     * a single value without brackets: "?status=paid".
     *
     * @return list<string>|null null when the bag holds nothing for the field
     */
    private function submittedList(string $key): ?array
    {
        if (isset($this->linked[$key])) {
            // A link filled this field in. The address of the page drops the parameter.
            $this->consumed[$key] = true;
        }

        $value = $this->in[$key] ?? null;
        if (is_string($value)) {
            return [$value];
        }

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : null;
    }

    private function seen(string $key): bool
    {
        $seen = $this->in['_seen'] ?? null;

        return is_array($seen) && in_array($key, $seen, true);
    }

    /**
     * Which option select() or radio() shows as chosen: what the browser sent when
     * that is one of the options, else the default, else the first option.
     *
     * @param non-empty-list<array{wire: string, value: mixed, label: string}> $choices
     */
    private function chosen(string $key, array $choices, mixed $default): int
    {
        $chosen = null;
        $raw = $this->submitted($key);
        if ($raw !== null) {
            $chosen = self::choose($choices, $raw);
        }
        if ($chosen === null && (is_string($default) || is_int($default) || is_float($default))) {
            $chosen = self::choose($choices, (string) $default);
        }

        return $chosen ?? 0;
    }

    /**
     * A group of options under one label: a radio button or a tick box for each.
     *
     * @param 'radio'|'checkbox'                                      $type
     * @param list<array{wire: string, value: mixed, label: string}> $choices
     * @param array<int,true>                                         $chosen  the places of the options that are chosen
     */
    private function choicesHtml(string $key, string $label, ?string $help, bool $horizontal, string $type, array $choices, array $chosen): string
    {
        $name = Text::e($type === 'radio' ? $key : $key . '[]');
        $layout = match (true) {
            $horizontal => ' sui-choices-row',
            count($choices) > self::MANY_CHOICES => ' sui-choices-many',
            default => '',
        };

        $items = '';
        $ids = [];
        foreach ($choices as $index => $choice) {
            // The id is made from the option and not from its place in the list. The page
            // script puts the focus back by id: when the list changes between two runs,
            // the focus stays on the option and does not move to the one that took its place.
            $id = 'f-' . $key . ':' . hash('crc32b', $choice['wire']);
            if (isset($ids[$id])) {
                $id .= '-' . $index;
            }
            $ids[$id] = true;

            $items .= '<label class="sui-check"><input type="' . $type . '" id="' . Text::e($id) . '" name="' . $name . '" value="' . Text::e($choice['wire']) . '"'
                . (isset($chosen[$index]) ? ' checked' : '') . '><span dir="auto">' . Text::e($choice['label']) . '</span></label>';
        }

        return '<fieldset class="sui-field sui-field-choices' . ($layout === '' ? '' : ' sui-field-wide') . '" id="' . Text::e('f-' . $key) . '"'
            . ($help === null ? '' : ' aria-describedby="' . Text::e('h-' . $key) . '"') . '>'
            . '<legend dir="auto">' . Text::e($label) . '</legend>'
            . ($choices === []
                ? '<p class="sui-help sui-choices-none" dir="auto">' . Text::e($this->t('choices.none')) . '</p>'
                : '<div class="sui-choices' . $layout . '">' . $items . '</div>')
            // See checkbox(). A group without options sends no marker: nobody could tick anything.
            . ($type === 'checkbox' && $choices !== [] ? '<input type="hidden" name="_seen[]" value="' . Text::e($key) . '">' : '')
            . ($help === null ? '' : '<p class="sui-help" id="' . Text::e('h-' . $key) . '" dir="auto">' . Text::e($help) . '</p>')
            . '</fieldset>';
    }

    private function remember(string $key, string $wire, mixed $value): void
    {
        $this->values[$key] = $value;
        if (strlen($wire) <= self::MAX_SAVED_BYTES) {
            $this->out[$key] = $wire;
        }
    }

    private function field(string $key, string $label, ?string $help, string $control): string
    {
        return '<div class="sui-field"><label for="' . Text::e('f-' . $key) . '" dir="auto">' . Text::e($label) . '</label>'
            . $control
            . ($help === null ? '' : '<p class="sui-help" dir="auto">' . Text::e($help) . '</p>')
            . '</div>';
    }

    private static function calloutHtml(string $kind, string $html): string
    {
        $role = $kind === 'error' || $kind === 'warning' ? 'alert' : 'status';

        return '<div class="sui-callout sui-' . $kind . '" role="' . $role . '" dir="auto">' . $html . '</div>';
    }

    private function failureHtml(\Throwable $error): string
    {
        if (!$this->debug) {
            return self::calloutHtml('error', Text::e($this->t('failure.text')));
        }

        return self::calloutHtml(
            'error',
            '<strong>' . Text::e($error::class) . '</strong><br>' . Text::lines(mb_scrub($error->getMessage(), 'UTF-8'))
            . '<br><span class="sui-where" dir="ltr">' . Text::e($error->getFile() . ':' . $error->getLine()) . '</span>'
            . '<details class="sui-trace"><summary>' . Text::e($this->t('failure.trace')) . '</summary><pre dir="ltr">' . Text::e($error->getTraceAsString()) . '</pre></details>',
        );
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($text, 'UTF-8')));
    }

    private static function manyLines(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $text);
    }

    private static function parseNumber(string $raw): int|float|null
    {
        $raw = trim($raw);

        // Up to 18 digits always fit in an integer.
        if (preg_match('/^[+-]?\d{1,18}$/D', $raw) === 1) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            $number = (float) $raw;

            return is_finite($number) ? $number : null;
        }

        return null;
    }

    private static function dateArgument(string|\DateTimeInterface|null $date): ?string
    {
        if ($date === null) {
            return null;
        }
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return self::validDate($date) ?? throw new \InvalidArgumentException(sprintf(
            'date_input() needs dates written as YYYY-MM-DD, not "%s".',
            $date,
        ));
    }

    private static function validDate(string $date): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $match) !== 1) {
            return null;
        }

        return checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $date : null;
    }

    /**
     * The options of select(), radio() and multiselect(): what the form carries
     * for each ("wire"), what the script gets back and what the user reads.
     *
     * @param array<array-key,mixed> $options
     * @param string                 $function the function that was called, for the message
     *
     * @return list<array{wire: string, value: mixed, label: string}>
     */
    private static function choices(array $options, bool $isList, string $function): array
    {
        $choices = [];

        foreach ($options as $optionKey => $option) {
            if (!is_string($option) && !is_int($option) && !is_float($option)) {
                throw new \InvalidArgumentException($function . '() options must be text or numbers.');
            }

            $value = $isList ? $option : $optionKey;
            $wire = self::wire((string) $value);
            // An option that is there twice is offered once. Options often come from
            // data (the country of every customer), and the form could not tell two
            // of the same value apart anyway.
            $choices[$wire] ??= ['wire' => $wire, 'value' => $value, 'label' => (string) $option];
        }

        return array_values($choices);
    }

    /**
     * A value the way it is compared with what the form sent. A browser sends
     * every line break as "\r\n", whatever the page said, so both sides use "\n".
     */
    private static function wire(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    /**
     * @param list<array{wire: string, value: mixed, label: string}> $choices
     */
    private static function choose(array $choices, string $sent): ?int
    {
        $sent = self::wire($sent);
        foreach ($choices as $index => $choice) {
            if ($choice['wire'] === $sent) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Refuses a default that is a place in a list of options and not one of them.
     * Quietly returning a text where the script expects 0 or 1 would end up in a database.
     *
     * @param array<array-key,mixed>                                  $options
     * @param list<array{wire: string, value: mixed, label: string}> $choices
     */
    private static function assertNoPosition(string $function, string $label, array $options, array $choices, mixed $default): void
    {
        if (is_int($default) && array_key_exists($default, $options) && self::choose($choices, (string) $default) === null) {
            throw new \LogicException(sprintf(
                '%s(): the default %d is not one of the options of "%s". PHP cannot tell [0 => \'No\', 1 => \'Yes\'] '
                . 'from the list [\'No\', \'Yes\'], so the texts were taken as the values. If the keys are the values, add byKey: true.',
                $function,
                $default,
                $label,
            ));
        }
    }

    /** Is this what a field for several values sends: a plain list of texts, limited in size? */
    private static function isShortList(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || !array_is_list($value) || count($value) > self::MAX_SAVED_FIELDS) {
            return false;
        }

        $bytes = 0;
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
            $bytes += strlen($item);
        }

        return $bytes <= self::MAX_SAVED_BYTES;
    }

    /**
     * The part of a submitted form that may be kept in the session: plain fields
     * (a text, or the list of texts of a field that takes several values), the
     * list of tick boxes that were on the page, and what the tables keep. All of
     * it is limited in size.
     *
     * @param array<array-key,mixed> $in
     *
     * @return array<array-key,mixed>
     */
    private static function keepable(array $in): array
    {
        $strings = static function (mixed $list): array {
            $kept = [];
            foreach (is_array($list) ? $list : [] as $item) {
                if (is_string($item) && strlen($item) <= 256 && count($kept) < self::MAX_SAVED_FIELDS) {
                    $kept[] = $item;
                }
            }

            return $kept;
        };

        $keep = [];
        $left = [];
        $count = 0;

        foreach ($in as $name => $value) {
            $name = (string) $name;
            if ($name === '' || strlen($name) > 128 || ++$count > self::MAX_SAVED_FIELDS) {
                continue;
            }

            if ($name[0] !== '_') {
                if (is_string($value) ? strlen($value) <= self::MAX_SAVED_BYTES : self::isShortList($value)) {
                    $keep[$name] = $value;
                } elseif ($value !== []) {
                    $left[$name] = true;
                }
            } elseif ($name === '_seen') {
                $keep['_seen'] = $strings($value);
            }
        }

        if ($left !== [] && isset($keep['_seen'])) {
            // A field whose value was not kept starts from its default again. With
            // the marker it would start from "nothing ticked".
            $keep['_seen'] = array_values(array_filter($keep['_seen'], static fn (string $name): bool => !isset($left[$name])));
        }

        return $keep + self::layout($in) + Table::keepable($in) + Uploads::keepable($in);
    }

    /**
     * How the user left the parts of a page that open and close, as far as a form
     * says it: which expanders are open, and which tab of each group is in view.
     * Checked and limited in size.
     *
     * The page script reports this without a run of the tool: see Kernel::noteLayout().
     *
     * @param array<array-key,mixed> $in
     *
     * @return array{_open?: array<array-key,string>, _tab?: array<array-key,string>}
     */
    public static function layout(array $in): array
    {
        $layout = [];

        // Which expanders are open: the key of each, and "1" or "0".
        foreach (is_array($in['_open'] ?? null) ? $in['_open'] : [] as $key => $state) {
            if (Keys::isValid((string) $key) && ($state === '0' || $state === '1') && count($layout['_open'] ?? []) < self::MAX_SAVED_FIELDS) {
                $layout['_open'][$key] = $state;
            }
        }

        // Which tab is in view: the key of each group of tabs, and the place and label of the tab.
        foreach (is_array($in['_tab'] ?? null) ? $in['_tab'] : [] as $key => $tab) {
            if (Keys::isValid((string) $key) && is_string($tab) && self::tabNamed($tab) !== null && count($layout['_tab'] ?? []) < self::MAX_SAVED_FIELDS) {
                $layout['_tab'][$key] = $tab;
            }
        }

        return $layout;
    }
}
