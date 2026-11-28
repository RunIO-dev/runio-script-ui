<?php
// Test: progress
// A task that takes long, for the browser tests. Every run that the page starts by itself does one step of it.

use function Runio\ScriptUi\{title, note, text, field_error, progress, refresh, button, expander, tabs, success, info, warning, rerun};

title('Test: progress');

$file = __DIR__ . '/../var/test-progress.json';
is_dir(dirname($file)) || mkdir(dirname($file), 0777, true);
$job = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$job = is_array($job) && is_int($job['done'] ?? null) ? $job : null;
$save = static function (?array $job) use ($file): void {
    file_put_contents($file, json_encode($job, JSON_THROW_ON_ERROR), LOCK_EX);
};

// The "worker". A run that nobody asked for does one of the four steps, so a test says when the task moves on.
// A task that waits for the worker is picked up first.
if ($job !== null && $job['done'] < 4 && ($_SERVER['HTTP_X_SCRIPT_UI'] ?? '') === 'refresh') {
    if ($job['waits'] ?? false) {
        unset($job['waits']);
    } else {
        $job['done']++;
    }
    $save($job);
}

if (button('Reset')) {
    $save(null);
    rerun();
}

if ($job === null) {
    note('No task.');

    if (button('Start')) {
        $save(['done' => 0]);
        rerun();
    }

    if (button('Start and say so')) {
        // What one writes first, and news for the page: a message next to a bar that has to go on.
        $job = ['done' => 0];
        $save($job);
        success('The task started.');
    }

    if (button('Queue and say so')) {
        // Nobody knows yet how far it is: the bar has no value.
        $job = ['done' => 0, 'waits' => true];
        $save($job);
        info('The task waits for a worker.');
    }

    if (button('Start, say so and run again')) {
        // The message goes along to the page that rerun() loads.
        $save(['done' => 0]);
        success('The task started.');
        rerun();
    }

    if (button('Start two and say so')) {
        // One task is over and one is under way.
        $job = ['done' => 0, 'two' => true];
        $save($job);
        success('The second task started.');
    }

    if (button('Show a full bar')) {
        $save(['done' => 4, 'full' => true]);
        rerun();
    }

    if (button('Start out of sight')) {
        $save(['done' => 0, 'folded' => true]);
        rerun();
    }

    if (button('Start behind a tab')) {
        $save(['done' => 0, 'behind' => true]);
        rerun();
    }

    if (button('Start and warn')) {
        // Bad news next to a bar that has to go on.
        $job = ['done' => 0];
        $save($job);
        warning('The task started late.');
    }

    if (button('Start with a wrong field')) {
        $job = ['done' => 0, 'asks' => true];
        $save($job);
        $wrong = true;
    }
}

if ($job !== null && ($job['full'] ?? false)) {
    // A bar that is full: nothing is under way. The page still runs again, as a dashboard does.
    refresh(2);
    progress('Working', 4, 4);
    if (button('Say something')) {
        success('Said.');
    }
} elseif ($job !== null && ($job['folded'] ?? false)) {
    // A task under way whose bar nobody sees: it is in an expander that is closed.
    refresh(2);
    expander('Details', static fn () => progress('Working', $job['done'], 4));
    if (button('Say something')) {
        success('Said.');
    }
} elseif ($job !== null && ($job['behind'] ?? false)) {
    // The same, behind a tab that is not the open one.
    refresh(2);
    tabs([
        'Summary' => static fn () => note('A task is under way.'),
        'Details' => static fn () => progress('Working', $job['done'], 4),
    ]);
    if (button('Say something')) {
        success('Said.');
    }
} elseif ($job !== null && ($job['two'] ?? false) && $job['done'] < 4) {
    refresh(2);
    progress('Unpacking', 1, 1);
    progress('Working', $job['done'], 4);
} elseif ($job !== null && ($job['asks'] ?? false) && $job['done'] < 4) {
    // A task under way next to a field. What is wrong with the field is said under it.
    refresh(2);
    text('Name');
    if ($wrong ?? false) {
        field_error('Name', 'A name is needed.');
    }
    progress('Working', $job['done'], 4);
} elseif ($job !== null && ($job['waits'] ?? false)) {
    refresh(2);
    progress('Waiting for a worker');
} elseif ($job !== null && $job['done'] < 4) {
    refresh(2);
    progress('Working', $job['done'], 4, text: $job['done'] . ' of 4 steps');
} elseif ($job !== null) {
    success('The task is done.');
}
