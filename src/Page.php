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
     * @param array<array-key,mixed> $in      the bag this run reads from
     * @param array<array-key,mixed> $carried  messages kept from the run that called rerun()
     * @param bool                   $fragment the page's own script asked: answer with the content only
     * @param array<array-key,true>  $linked   names in the bag that came from the address ("?customer-email=...")
     * @param string|null            $setLanguage the language of the built-in texts, when one is set for all pages
     * @param array<array-key,mixed> $ownTexts built-in texts the application replaced: language => (id => text)
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
        $choices = self::choices($options, $isList);
        $id = Text::e('f-' . $key);

        if ($byKey === null && $isList && is_int($default) && array_key_exists($default, $options) && self::choose($choices, (string) $default) === null) {
            // Quietly returning a text where the script expects 0 or 1 would end up in a database.
            throw new \LogicException(sprintf(
                'select(): the default %d is not one of the options of "%s". PHP cannot tell [0 => \'No\', 1 => \'Yes\'] '
                . 'from the list [\'No\', \'Yes\'], so the texts were taken as the values. If the keys are the values, add byKey: true.',
                $default,
                $label,
            ));
        }

        if ($choices === []) {
            $this->values[$key] = null;
            $this->add($this->field($key, $label, $help, '<select class="sui-input" id="' . $id . '" name="' . Text::e($key) . '" disabled></select>'));

            return null;
        }

        // The browser sends back the text of a value. It only counts if it is one of the options.
        $chosen = null;
        $raw = $this->submitted($key);
        if ($raw !== null) {
            $chosen = self::choose($choices, $raw);
        }
        if ($chosen === null && (is_string($default) || is_int($default) || is_float($default))) {
            $chosen = self::choose($choices, (string) $default);
        }
        $chosen ??= 0;

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

    // ------------------------------------------------------------------- data

    /**
     * @param iterable<mixed>             $rows
     * @param array<array-key,mixed>|null $columns
     *
     * @return list<mixed> the selected rows, exactly as they were passed in
     */
    public function table(iterable $rows, bool $selectable, ?array $columns, ?string $key, string $rowKey, int $limit, ?string $empty): array
    {
        $key = $this->claimTable($key);

        $originals = [];
        $records = [];
        foreach ($rows as $row) {
            $originals[] = $row;
            $records[] = Rows::record($row);
        }

        if ($records === []) {
            $this->add('<p class="sui-empty" dir="auto">' . Text::e($empty ?? $this->t('table.empty')) . '</p>');

            return [];
        }

        $fields = Rows::columns($records, $columns, 'table()');
        $sort = $this->tableSort($key, count($fields));

        $order = array_keys($records);
        if ($sort !== null) {
            $field = $fields[$sort[0]]['field'];
            $descending = $sort[1] === 'desc';
            usort($order, static function (int $a, int $b) use ($records, $field, $descending): int {
                $left = $records[$a][$field] ?? null;
                $right = $records[$b][$field] ?? null;
                if ($left === null || $right === null) {
                    // Empty values go last, whichever way the column is sorted.
                    return ($left === null) <=> ($right === null);
                }

                $result = self::compare($left, $right);

                return $descending ? -$result : $result;
            });
            $this->out['_sort'][$key] = $sort[0] . ':' . $sort[1];
        }

        $visible = array_slice($order, 0, max(1, $limit));

        $ids = [];
        $chosen = [];
        if ($selectable) {
            // The browser only says which rows are ticked. The rows themselves always
            // come from $rows, so a forged form can never add a row.
            $wanted = [];
            $submitted = is_array($this->in['_sel'] ?? null) ? ($this->in['_sel'][$key] ?? null) : null;
            foreach (is_array($submitted) ? $submitted : [] as $id) {
                if (is_string($id)) {
                    $wanted[$id] = true;
                }
            }

            $ids = self::rowIds($records, $visible, $rowKey, $fields);
            foreach ($ids as $index => $id) {
                if (isset($wanted[$id['wire']])) {
                    $chosen[$index] = true;
                }
            }
            ksort($chosen);

            $kept = [];
            $named = [];
            foreach (array_keys($chosen) as $index) {
                $kept[] = $ids[$index]['wire'];
                $named[] = $ids[$index]['name'];
            }
            if ($kept !== []) {
                $this->out['_sel'][$key] = $kept;
            }
            $this->values[$key] = $named;
        }

        $this->add($this->tableHtml($key, $fields, $records, $visible, $ids, $chosen, $sort, $selectable));

        $picked = [];
        foreach (array_keys($chosen) as $index) {
            $picked[] = $originals[$index];
        }

        return $picked;
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
                    . '<button type="submit" class="sui-btn sui-btn-' . ($style === 'danger' ? 'danger' : 'primary') . '" id="' . Text::e('b-' . $key . '-yes') . '" data-sui-return="' . Text::e('b-' . $key) . '" name="_click" value="' . Text::e($yesValue) . '" dir="auto">' . Text::e($yes ?? $this->t('confirm.yes')) . '</button>'
                    . '<button type="submit" class="sui-btn sui-btn-default" id="' . Text::e('b-' . $key . '-no') . '" data-sui-return="' . Text::e('b-' . $key) . '" name="_click" value="' . Text::e($key . ':no') . '" dir="auto">' . Text::e($no ?? $this->t('confirm.no')) . '</button>'
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
        $cells = [];

        try {
            foreach ($columns as $column) {
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

    /** @return array<array-key,mixed> */
    public function values(): array
    {
        return $this->values;
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
        return $this->rerun || $this->download !== null ? self::keepable($this->in) : $this->out;
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
        if ($key !== null) {
            return $this->claim($key, 'table');
        }

        $candidate = 'table';
        for ($n = 2; isset($this->used[$candidate]); $n++) {
            $candidate = 'table-' . $n;
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

    private function seen(string $key): bool
    {
        $seen = $this->in['_seen'] ?? null;

        return is_array($seen) && in_array($key, $seen, true);
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
     * @param array<array-key,mixed> $options
     *
     * @return list<array{wire: string, value: mixed, label: string}>
     */
    private static function choices(array $options, bool $isList): array
    {
        $choices = [];

        foreach ($options as $optionKey => $option) {
            if (!is_string($option) && !is_int($option) && !is_float($option)) {
                throw new \InvalidArgumentException('select() options must be text or numbers.');
            }

            $value = $isList ? $option : $optionKey;
            $choices[] = ['wire' => (string) $value, 'value' => $value, 'label' => (string) $option];
        }

        return $choices;
    }

    /**
     * @param list<array{wire: string, value: mixed, label: string}> $choices
     */
    private static function choose(array $choices, string $wire): ?int
    {
        foreach ($choices as $index => $choice) {
            if ($choice['wire'] === $wire) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The sort order of a table: the saved one, changed by a click on a header.
     * A header cycles through ascending, descending and unsorted.
     *
     * @return array{int, string}|null column index and "asc" or "desc"
     */
    private function tableSort(string $key, int $columnCount): ?array
    {
        $sort = null;
        $saved = is_array($this->in['_sort'] ?? null) ? ($this->in['_sort'][$key] ?? null) : null;

        if (is_string($saved)
            && preg_match('/^(\d{1,3}):(asc|desc)$/D', $saved, $match) === 1
            && (int) $match[1] < $columnCount) {
            $sort = [(int) $match[1], $match[2]];
        }

        if ($this->click !== ''
            && preg_match('/^' . preg_quote($key, '/') . ':sort:(\d{1,3})$/D', $this->click, $match) === 1
            && (int) $match[1] < $columnCount) {
            $index = (int) $match[1];
            $sort = match (true) {
                $sort === null || $sort[0] !== $index => [$index, 'asc'],
                $sort[1] === 'asc' => [$index, 'desc'],
                default => null,
            };
        }

        return $sort;
    }

    /**
     * @param list<array{field: array-key, label: string}> $fields
     * @param list<array<array-key,mixed>>                 $records
     * @param list<int>                                    $visible
     * @param array<int,array{wire: string, name: string}> $ids
     * @param array<int,true>                              $chosen
     * @param array{int, string}|null                      $sort
     */
    private function tableHtml(string $key, array $fields, array $records, array $visible, array $ids, array $chosen, ?array $sort, bool $selectable): string
    {
        // A column of numbers is set to the right so the digits line up.
        $numeric = [];
        foreach ($fields as $column => $field) {
            $found = false;
            $numeric[$column] = true;
            foreach ($visible as $index) {
                $value = $records[$index][$field['field']] ?? null;
                if ($value === null) {
                    continue;
                }
                $found = true;
                if (!is_int($value) && !is_float($value)) {
                    $numeric[$column] = false;
                    break;
                }
            }
            $numeric[$column] = $numeric[$column] && $found;
        }

        $html = '<div class="sui-table-wrap"><table class="sui-table"><thead><tr>';
        if ($selectable) {
            $html .= '<th scope="col" class="sui-pick"><span class="sui-sr">' . Text::e($this->t('table.select')) . '</span></th>';
        }
        foreach ($fields as $column => $field) {
            $direction = $sort !== null && $sort[0] === $column ? $sort[1] : null;
            $html .= '<th scope="col"' . ($numeric[$column] ? ' class="sui-num"' : '')
                . ($direction === null ? '' : ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"') . '>'
                . '<button type="submit" class="sui-sort" id="' . Text::e('s-' . $key . '-' . $column) . '" name="_click" value="' . Text::e($key . ':sort:' . $column) . '" dir="auto">'
                . Text::e($field['label']) . '</button></th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($visible as $position => $index) {
            $html .= '<tr' . (isset($chosen[$index]) ? ' class="sui-picked"' : '') . '>';
            if ($selectable) {
                $html .= '<td class="sui-pick"><input type="checkbox" id="' . Text::e('f-' . $key . '-' . $position) . '" name="' . Text::e('_sel[' . $key . '][]') . '" value="' . Text::e($ids[$index]['wire']) . '"'
                    . ' aria-label="' . Text::e($this->t('table.select_row', ['row' => $ids[$index]['name']])) . '"' . (isset($chosen[$index]) ? ' checked' : '') . '></td>';
            }
            foreach ($fields as $column => $field) {
                $value = $records[$index][$field['field']] ?? null;
                $text = is_bool($value) ? $this->t($value ? 'cell.yes' : 'cell.no') : self::cell($value);
                $html .= '<td' . match (true) {
                    // A number reads left to right on every page, so "-5" never turns into "5-".
                    $numeric[$column] => ' class="sui-num" dir="ltr"',
                    // Short values stay on one line and the table scrolls sideways. Long text may wrap.
                    mb_strlen($text, 'UTF-8') > 40 => ' class="sui-long" dir="auto"',
                    default => ' dir="auto"',
                } . '>' . Text::e($text) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        $texts = $this->texts();
        $total = count($records);
        $shown = count($visible);
        $foot = match (true) {
            $shown < $total => $texts->get('table.rows_of', ['shown' => $texts->number($shown), 'count' => $texts->number($total)]),
            $total === 1 => $texts->get('table.rows_one'),
            default => $texts->get('table.rows', ['count' => $texts->number($total)]),
        };
        if ($selectable) {
            $foot .= ' · ' . $texts->get('table.selected', ['count' => $texts->number(count($chosen))]);
        }
        $html .= '<p class="sui-table-foot">' . Text::e($foot) . '</p>';

        if ($sort !== null) {
            $html .= '<input type="hidden" name="' . Text::e('_sort[' . $key . ']') . '" value="' . $sort[0] . ':' . $sort[1] . '">';
        }

        return $html;
    }

    /**
     * What the tick box of each visible row sends, and what the row is called in the log.
     *
     * A row is known by the value in its id column ("...:k:1042"). When several
     * rows share a value, the later ones are numbered ("...:k2:1042"), so one tick
     * never selects two rows. The numbering follows the order the rows were passed
     * in, so sorting the table changes nothing.
     *
     * A row without a usable id is known by what is in it ("...:h:9f2c..."). The
     * tick follows the row when the list is filtered or grows. When the row itself
     * changes, the tick is dropped. It never moves to a row the user did not tick.
     *
     * Every value starts with a mark made from the id column and the columns of
     * the table. A tick made in a table of orders then never counts in a table of
     * customers that is drawn in its place.
     *
     * @param list<array<array-key,mixed>>                 $records
     * @param list<int>                                    $visible
     * @param list<array{field: array-key, label: string}> $fields
     *
     * @return array<int,array{wire: string, name: string}>
     */
    private static function rowIds(array $records, array $visible, string $rowKey, array $fields): array
    {
        $shape = $rowKey;
        foreach ($fields as $field) {
            $shape .= "\0" . $field['field'];
        }
        $mark = substr(hash('sha256', $shape), 0, 6);

        $shown = array_fill_keys($visible, true);
        $ids = [];
        $seen = [];

        foreach ($records as $index => $record) {
            $value = $record[$rowKey] ?? null;

            if ((is_string($value) || is_int($value) || is_float($value)) && (string) $value !== '') {
                $kind = 'k';
                $name = (string) $value;
            } elseif (isset($shown[$index])) {
                $kind = 'h';
                $name = substr(hash('sha256', Text::json($record, false)), 0, 16);
            } else {
                continue;
            }

            $count = $seen[$kind . $name] = ($seen[$kind . $name] ?? 0) + 1;
            if (isset($shown[$index])) {
                $ids[$index] = [
                    'wire' => $mark . ':' . $kind . ($count === 1 ? '' : $count) . ':' . $name,
                    'name' => $kind === 'k' ? $name : '#' . ($index + 1),
                ];
            }
        }

        return $ids;
    }

    /**
     * A value as text. Sorting compares these texts, so they are the same in
     * every language: the table writes yes and no in the language of the page itself.
     */
    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_string($value) => mb_scrub($value, 'UTF-8'),
            is_float($value) && !is_finite($value) => Text::exactNumber($value),
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i'),
            $value instanceof \BackedEnum => (string) $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \Stringable => mb_scrub((string) $value, 'UTF-8'),
            default => Text::json($value, false),
        };
    }

    private static function compare(mixed $left, mixed $right): int
    {
        $isNumber = static fn (mixed $value): bool => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
        $leftIsNumber = $isNumber($left);

        // Numbers come before words. Comparing a number with a word by another rule
        // than two numbers or two words would leave a mixed column in no order at all.
        if ($leftIsNumber !== $isNumber($right)) {
            return $leftIsNumber ? -1 : 1;
        }

        if ($leftIsNumber) {
            // Two long numbers can be the same float and still differ: compare their digits then.
            return ((float) $left <=> (float) $right) ?: strnatcmp((string) $left, (string) $right);
        }

        return strnatcasecmp(self::cell($left), self::cell($right)) ?: strcmp(self::cell($left), self::cell($right));
    }

    /**
     * The part of a submitted form that may be kept in the session: plain fields
     * and the three internal lists, all limited in size.
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
        $count = 0;

        foreach ($in as $name => $value) {
            $name = (string) $name;
            if ($name === '' || strlen($name) > 128 || ++$count > self::MAX_SAVED_FIELDS) {
                continue;
            }

            if ($name[0] !== '_') {
                if (is_string($value) && strlen($value) <= self::MAX_SAVED_BYTES) {
                    $keep[$name] = $value;
                }
            } elseif ($name === '_seen') {
                $keep['_seen'] = $strings($value);
            } elseif ($name === '_sel' && is_array($value)) {
                foreach ($value as $table => $ids) {
                    $keep['_sel'][$table] = $strings($ids);
                }
            } elseif ($name === '_sort' && is_array($value)) {
                foreach ($value as $table => $state) {
                    if (is_string($state) && strlen($state) <= 16) {
                        $keep['_sort'][$table] = $state;
                    }
                }
            }
        }

        return $keep;
    }
}
