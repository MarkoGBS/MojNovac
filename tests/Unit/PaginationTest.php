<?php

namespace Tests\Unit;

use App\Controllers\UserController;
use App\Models\Account;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PaginationTest extends TestCase
{
    public function testTransactionsReachEveryPageWithoutDuplicatesOrOtherUsersRecords(): void
    {
        $db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT)');
        $db->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE transactions (
            id INTEGER PRIMARY KEY, account_id INTEGER, destination_account_id INTEGER,
            category_id INTEGER, created_at TEXT
        )');
        $db->exec('INSERT INTO accounts VALUES (1, 10, "Mine"), (2, 20, "Other")');
        $insert = $db->prepare('INSERT INTO transactions (id, account_id, created_at) VALUES (?, ?, ?)');
        for ($id = 1; $id <= 24; $id++) {
            $insert->execute([$id, $id === 24 ? 2 : 1, '2026-09-23 12:00:00']);
        }

        $accounts = new Account($db);
        $ids = [];
        for ($number = 1; $number <= 3; $number++) {
            $page = $accounts->transactionPage(10, $number);
            self::assertSame(23, $page['total']);
            self::assertSame(3, $page['pages']);
            self::assertCount($number === 3 ? 3 : 10, $page['items']);
            $ids = array_merge($ids, array_column($page['items'], 'id'));
        }
        self::assertSame(range(23, 1), $ids);
        self::assertSame(1, $accounts->transactionPage(10, -1)['page']);
        self::assertSame([3, 2, 1], array_column($accounts->transactionPage(10, PHP_INT_MAX)['items'], 'id'));
        $empty = $accounts->transactionPage(999, 999);
        self::assertSame([], $empty['items']);
        self::assertSame(0, $empty['total']);
        self::assertSame(1, $empty['page']);
        self::assertSame(1, $empty['pages']);
    }

    public function testMalformedPageInputsFallBackWithoutWarnings(): void
    {
        $reflection = new ReflectionClass(UserController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('pageNumber');
        $previous = $_GET;
        try {
            foreach ([[], ['2'], '-1', '0', '2.5', 'abc', '999999999999999999999999'] as $value) {
                $_GET = ['tickets_page' => $value];
                self::assertSame(1, $method->invoke($controller, 'tickets_page'));
            }
            $_GET = ['tickets_page' => '2', 'transactions_page' => '3'];
            self::assertSame(2, $method->invoke($controller, 'tickets_page'));
            self::assertSame(3, $method->invoke($controller, 'transactions_page'));
            self::assertSame(1, $method->invoke($controller));
        } finally {
            $_GET = $previous;
        }
    }
}
