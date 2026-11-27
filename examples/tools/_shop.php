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

    /** @param array{customers: list<array<string,mixed>>, orders: list<array<string,mixed>>, coupons?: list<array<string,mixed>>} $data */
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

    /**
     * A few orders in the shape that checkOrders() takes, to try the import tool with.
     *
     * @return list<array{email: string, items: string, total: float}>
     */
    public static function exampleOrders(): array
    {
        return [
            ['email' => 'ava@example.com', 'items' => 'Notebook and pen set', 'total' => 24.5],
            ['email' => 'omar@example.com', 'items' => 'Monitor arm', 'total' => 89.0],
            ['email' => 'lina@example.com', 'items' => 'Desk lamp', 'total' => 39.9],
        ];
    }

    /**
     * Looks at rows that came from a file and says which of them are orders. A
     * row needs the columns email, items and total. Nothing is added to the shop yet.
     *
     * @param list<array<array-key,string>> $rows as $file->rows() returns them: every cell is text
     *
     * @return array{0: list<array{email: string, customer: string, items: string, total: float}>, 1: list<string>}
     *         the orders that can be added, and what is wrong with the other rows
     */
    public function checkOrders(array $rows): array
    {
        $customers = array_column($this->data['customers'], 'name', 'email');
        $orders = $problems = [];

        foreach ($rows as $index => $row) {
            // The file comes from a user. Its columns may be missing or written "Email".
            $row = array_change_key_case($row, CASE_LOWER);
            $missing = array_diff(['email', 'items', 'total'], array_map('strval', array_keys($row)));
            if ($missing !== []) {
                return [[], ['The first line has to name the columns email, items and total. Missing: ' . implode(', ', $missing) . '.']];
            }

            $number = $index + 1;
            $email = strtolower(trim($row['email']));
            $items = trim($row['items']);
            // A spreadsheet in half of Europe writes one and a half as 1,5.
            $total = trim($row['total']);
            $amount = str_contains($total, '.') ? $total : str_replace(',', '.', $total);

            if (!isset($customers[$email])) {
                $problems[] = 'Row ' . $number . ': no customer has the email "' . mb_substr($email, 0, 80) . '".';
            } elseif ($items === '') {
                $problems[] = 'Row ' . $number . ': the items are missing.';
            } elseif (mb_strlen($items) > 200) {
                $problems[] = 'Row ' . $number . ': the items are longer than 200 characters.';
            } elseif (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 1_000_000) {
                // "1e999" is a number to PHP, and an endless one. No shop sells for that.
                $problems[] = 'Row ' . $number . ': "' . mb_substr($total, 0, 40) . '" is not an amount.';
            } else {
                $orders[] = ['email' => $email, 'customer' => $customers[$email], 'items' => $items, 'total' => round((float) $amount, 2)];
            }
        }

        return [$orders, $problems];
    }

    /**
     * Adds orders that checkOrders() returned. They are paid and placed today.
     *
     * @param list<array{email: string, customer: string, items: string, total: float}> $orders
     */
    public function addOrders(array $orders): void
    {
        $id = max([1000, ...array_column($this->data['orders'], 'id')]);

        foreach ($orders as $order) {
            $this->data['orders'][] = [
                'id' => ++$id,
                'email' => $order['email'],
                'customer' => $order['customer'],
                'items' => $order['items'],
                'total' => $order['total'],
                'status' => 'paid',
                'created_at' => date('Y-m-d'),
            ];
        }

        $this->save();
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

    /** @return array<string,mixed>|null the customer with this email, however it is written */
    public function customer(string $email): ?array
    {
        foreach ($this->data['customers'] as $customer) {
            if (strcasecmp($customer['email'], trim($email)) === 0) {
                return $customer;
            }
        }

        return null;
    }

    /** @return list<array{code: string, percent: int, until: string, given: string}> the coupons of a customer, newest first */
    public function coupons(string $email): array
    {
        $coupons = [];
        foreach ($this->data['coupons'] ?? [] as $coupon) {
            if ($coupon['email'] === $email) {
                unset($coupon['email']);
                $coupons[] = $coupon;
            }
        }

        return array_reverse($coupons);
    }

    /** Gives a customer a coupon and returns its code, such as "AVA10-3". */
    public function addCoupon(string $email, int $percent, string $until): string
    {
        $code = strtoupper(substr($email, 0, 3)) . $percent . '-' . (count($this->data['coupons'] ?? []) + 1);
        $this->data['coupons'][] = ['email' => $email, 'code' => $code, 'percent' => $percent, 'until' => $until, 'given' => date('Y-m-d')];
        $this->save();

        return $code;
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

        // A value that cannot be written as JSON is an error here. Without the flag
        // the result would be false, and the file would be emptied.
        file_put_contents(self::FILE, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
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
