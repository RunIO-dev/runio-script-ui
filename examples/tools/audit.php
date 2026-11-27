<?php
// Audit log
// Every button press and every download, who it was and with which inputs.

require_once __DIR__ . '/_shop.php';

use function Runio\ScriptUi\{title, note, table, button, success, rerun};

title('Audit log');
note('The "log" setting in _config.php is called for every button a user presses and for every file a user downloads. This demo writes one line per action to examples/var/audit.log.');

$file = __DIR__ . '/../var/audit.log';
$entries = [];

foreach (is_file($file) ? array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
    $entry = json_decode($line, true);
    if (is_array($entry)) {
        $entries[] = [
            'time' => date('Y-m-d H:i:s', (int) strtotime((string) $entry['time'])),
            'user' => $entry['user'] ?? 'local',
            'tool' => $entry['tool'],
            'action' => $entry['action'],
            'confirmed' => $entry['confirmed'],
            'file' => $entry['download']['file'] ?? null,
            'inputs' => $entry['inputs'],
            // What the page said is wrong with its inputs in that run. A press that found something wrong did not do its work.
            'found_wrong' => implode(' ', array_merge([], ...array_values(array_filter((array) ($entry['field_errors'] ?? []), is_array(...))))),
        ];
    }
}

table($entries, search: true, empty: 'Nothing logged yet. Refund an order, then come back.');

if (button('Reset the demo data', confirm: 'Bring back the sample orders and empty this log?')) {
    Shop::open()->reset();
    if (is_file($file)) {
        unlink($file);
    }

    success('The demo data is fresh again.');
    rerun();
}
