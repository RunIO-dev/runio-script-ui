<?php

declare(strict_types=1);

/**
 * A stand-in for your application: a small shop with customers and orders,
 * kept in one JSON file under examples/var. In a real tool this is where you
 * would include your own bootstrap file and use your own database.
 *
 * The file name starts with an underscore, so it is a helper, not a tool.
 */
final class Shop
{
    private const FILE = __DIR__ . '/../var/shop.json';

    private const CUSTOMERS = [
        ['Ava Morgan', 'ava@example.com', 'United Kingdom'],
        ['Omar Haddad', 'omar@example.com', 'Jordan'],
        ['Lina Saleh', 'lina@example.com', 'Egypt'],
        ['Noah Becker', 'noah@example.com', 'Germany'],
        ['Mia Rossi', 'mia@example.com', 'Italy'],
        ['Yusuf Kaya', 'yusuf@example.com', 'Türkiye'],
        ['Sara Khalil', 'sara@example.com', 'Saudi Arabia'],
        ['Leo Martin', 'leo@example.com', 'France'],
    ];

    private const ITEMS = ['Notebook', 'Desk lamp', 'Backpack', 'Headphones', 'Water bottle', 'Keyboard', 'Monitor arm', 'Pen set'];

    /** @param array{customers: list<array<string,mixed>>, orders: list<array<string,mixed>>} $data */
    private function __construct(private array $data)
    {
    }

    public static function open(): self
    {
        if (!is_file(self::FILE)) {
            $shop = new self(self::seed());
            $shop->save();

            return $shop;
        }

        $data = json_decode((string) file_get_contents(self::FILE), true);
        if (!is_array($data) || !isset($data['customers'], $data['orders'])) {
            $data = self::seed();
        }

        return new self($data);
    }

    /** Throws the data away and starts again with fresh sample data. */
    public function reset(): void
    {
        $this->data = self::seed();
        $this->save();
    }

    /** @return list<array<string,mixed>> newest first */
    public function orders(?string $email = null, ?string $status = null, ?int $days = null): array
    {
        $since = $days === null ? null : date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

        $orders = array_filter($this->data['orders'], static fn (array $order): bool
            => ($email === null || strcasecmp($order['email'], trim($email)) === 0)
            && ($status === null || $order['status'] === $status)
            && ($since === null || $order['created_at'] >= $since));

        usort($orders, static fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        return $orders;
    }

    public function refund(int $id): void
    {
        $this->setStatus($id, 'refunded');
    }

    public function ship(int $id): void
    {
        $this->setStatus($id, 'shipped');
    }

    private function setStatus(int $id, string $status): void
    {
        foreach ($this->data['orders'] as &$order) {
            if ($order['id'] === $id) {
                $order['status'] = $status;
            }
        }
        unset($order);

        $this->save();
    }

    /** @return list<array<string,mixed>> every customer with what they ordered and spent */
    public function customers(): array
    {
        $customers = [];

        foreach ($this->data['customers'] as $customer) {
            $orders = $this->orders(email: $customer['email']);
            $kept = array_filter($orders, static fn (array $order): bool => $order['status'] !== 'refunded');

            $customers[] = $customer + [
                'orders' => count($orders),
                'refunds' => count($orders) - count($kept),
                'spent' => round(array_sum(array_column($kept, 'total')), 2),
            ];
        }

        return $customers;
    }

    /** @return list<array{day: string, revenue: float, orders: int, refunds: int}> one row per day, oldest first */
    public function daily(int $days): array
    {
        $rows = [];
        for ($ago = $days - 1; $ago >= 0; $ago--) {
            $date = date('Y-m-d', strtotime('-' . $ago . ' days'));
            $rows[$date] = ['day' => date('M j', (int) strtotime($date)), 'revenue' => 0.0, 'orders' => 0, 'refunds' => 0];
        }

        foreach ($this->data['orders'] as $order) {
            if (!isset($rows[$order['created_at']])) {
                continue;
            }
            if ($order['status'] === 'refunded') {
                $rows[$order['created_at']]['refunds']++;
            } else {
                $rows[$order['created_at']]['orders']++;
                $rows[$order['created_at']]['revenue'] = round($rows[$order['created_at']]['revenue'] + $order['total'], 2);
            }
        }

        return array_values($rows);
    }

    private function save(): void
    {
        if (!is_dir(dirname(self::FILE))) {
            mkdir(dirname(self::FILE), 0777, true);
        }

        file_put_contents(self::FILE, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /**
     * Sample data: the same shape on every machine, with dates that end today.
     *
     * @return array{customers: list<array<string,mixed>>, orders: list<array<string,mixed>>}
     */
    private static function seed(): array
    {
        // A tiny random number generator with a fixed start, so the data is always the same.
        $state = 20261126;
        $next = static function (int $max) use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;

            return intdiv($state, 65536) % $max;
        };

        $customers = [];
        foreach (self::CUSTOMERS as $index => [$name, $email, $country]) {
            $customers[] = [
                'id' => $index + 1,
                'name' => $name,
                'email' => $email,
                'country' => $country,
                'joined' => date('Y-m-d', strtotime('-' . (40 + $next(500)) . ' days')),
            ];
        }

        $orders = [];
        $id = 1000;
        for ($ago = 29; $ago >= 0; $ago--) {
            $count = 1 + $next(4);
            for ($i = 0; $i < $count; $i++) {
                $customer = self::CUSTOMERS[$next(count(self::CUSTOMERS))];
                $roll = $next(10);
                $orders[] = [
                    'id' => ++$id,
                    'email' => $customer[1],
                    'customer' => $customer[0],
                    'items' => self::ITEMS[$next(count(self::ITEMS))] . ($next(3) === 0 ? ' and ' . strtolower(self::ITEMS[$next(count(self::ITEMS))]) : ''),
                    'total' => round(15 + $next(18000) / 100, 2),
                    'status' => $roll === 0 ? 'refunded' : ($roll < 5 || $ago < 3 ? 'paid' : 'shipped'),
                    'created_at' => date('Y-m-d', strtotime('-' . $ago . ' days')),
                ];
            }
        }

        return ['customers' => $customers, 'orders' => $orders];
    }
}
