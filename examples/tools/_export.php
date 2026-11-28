<?php

declare(strict_types=1);

/**
 * A stand-in for a task that takes long: an export of orders that a worker
 * would write, one order after the other. Here nobody works. The export is a
 * start time in a file under examples/var, and how far it is follows from the
 * clock.
 *
 * That is the one thing a real task does differently: its worker writes down
 * how far it is, and the tool reads that. Everything else is the same.
 *
 * The file name starts with an underscore, so it is a helper, not a tool.
 */
final class Export
{
    private const FILE = __DIR__ . '/../var/export.json';

    /** How many orders one export writes. */
    public const ORDERS = 2400;

    /** The seconds until a worker takes the export, and the seconds it then works. */
    private const WAIT = 3;
    private const WORK = 24;

    /**
     * @param string $state "none", "waiting", "running", "finished" or "stopped"
     * @param int    $done  how many orders are written
     */
    private function __construct(
        public readonly string $state,
        public readonly int $done,
    ) {
    }

    public static function read(): self
    {
        $saved = self::saved();
        if ($saved === null) {
            return new self('none', 0);
        }

        // An export that was stopped stays where it was.
        $worked = ($saved['stopped'] ?? time()) - $saved['started'] - self::WAIT;
        $done = (int) max(0, min(self::ORDERS, floor($worked * self::ORDERS / self::WORK)));

        return new self(match (true) {
            $done >= self::ORDERS => 'finished',
            isset($saved['stopped']) => 'stopped',
            $worked < 0 => 'waiting',
            default => 'running',
        }, $done);
    }

    public static function start(): void
    {
        // "Start" pressed twice, or by two people: there is still one export.
        // A real tool asks its database that in one statement.
        if (in_array(self::read()->state, ['waiting', 'running'], true)) {
            return;
        }

        self::save(['started' => time()]);
    }

    public static function stop(): void
    {
        $saved = self::saved();
        if ($saved !== null && !isset($saved['stopped'])) {
            self::save(['started' => $saved['started'], 'stopped' => time()]);
        }
    }

    /** @return list<array{id: int, customer: string, total: float}> what the worker wrote */
    public static function orders(): array
    {
        $names = ['Ava Morgan', 'Omar Haddad', 'Lina Saleh', 'Noah Becker', 'Mia Rossi', 'Yusuf Kaya'];
        $orders = [];
        for ($number = 1; $number <= self::ORDERS; $number++) {
            $orders[] = ['id' => 700000 + $number, 'customer' => $names[$number % 6], 'total' => (500 + ($number * 7919) % 49500) / 100];
        }

        return $orders;
    }

    /** @return array{started: int, stopped?: int}|null */
    private static function saved(): ?array
    {
        $saved = is_file(self::FILE) ? json_decode((string) file_get_contents(self::FILE), true) : null;
        if (!is_array($saved) || !is_int($saved['started'] ?? null)) {
            return null;
        }

        return is_int($saved['stopped'] ?? null) ? ['started' => $saved['started'], 'stopped' => $saved['stopped']] : ['started' => $saved['started']];
    }

    /** @param array{started: int, stopped?: int} $job */
    private static function save(array $job): void
    {
        is_dir(dirname(self::FILE)) || mkdir(dirname(self::FILE), 0777, true);

        // Written beside the file and then moved over it: a page that reads in
        // the same moment gets the old export or the new one, never half a file.
        $beside = self::FILE . '.' . bin2hex(random_bytes(4));
        file_put_contents($beside, json_encode($job, JSON_THROW_ON_ERROR));
        rename($beside, self::FILE);
    }
}
