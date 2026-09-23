<?php

namespace Tests\Unit;

use App\Models\User;
use DomainException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const REGISTRATION = [
        'firstName' => 'Željko',
        'lastName' => 'Petrović',
        'dateOfBirth' => '2000-02-29',
        'email' => 'user@example.com',
        'password' => 'original-password',
        'passwordConfirmation' => 'original-password',
    ];

    private PDO $db;
    private User $users;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
                first_name TEXT, last_name TEXT, date_of_birth TEXT,
                email TEXT NOT NULL UNIQUE, password TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "user", active INTEGER NOT NULL DEFAULT 1
            )'
        );
        $this->db->exec(
            'CREATE TABLE accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id),
                name TEXT NOT NULL, balance NUMERIC NOT NULL DEFAULT 0
            )'
        );
        $this->users = new User($this->db);
    }

    public function testRegistrationStoresProfileAndHashedPasswordWithInitialAccount(): void
    {
        $this->register(['firstName' => ' Željko ', 'lastName' => ' Petrović ', 'email' => ' user@example.com ']);
        $user = $this->db->query('SELECT * FROM users')->fetch();
        $account = $this->db->query('SELECT * FROM accounts')->fetch();

        self::assertSame('Željko', $user['first_name']);
        self::assertSame('Petrović', $user['last_name']);
        self::assertSame('Željko Petrović', $user['name']);
        self::assertSame('2000-02-29', $user['date_of_birth']);
        self::assertSame('user@example.com', $user['email']);
        self::assertSame('user', $user['role']);
        self::assertNotSame(self::REGISTRATION['password'], $user['password']);
        self::assertTrue(password_verify(self::REGISTRATION['password'], $user['password']));
        self::assertSame($user['id'], $account['user_id']);
        self::assertSame('Glavni račun', $account['name']);
        self::assertSame(0.0, (float) $account['balance']);
        self::assertFalse($this->db->inTransaction());
    }

    public function testTodayAndUnicodeCharacterLimitsAreAccepted(): void
    {
        $this->register([
            'firstName' => str_repeat('Ž', 100),
            'lastName' => str_repeat('ć', 100),
            'dateOfBirth' => date('Y-m-d'),
            'password' => str_repeat('ž', 8),
            'passwordConfirmation' => str_repeat('ž', 8),
        ]);
        $user = $this->db->query('SELECT * FROM users')->fetch();

        self::assertSame(201, mb_strlen($user['name'], 'UTF-8'));
        self::assertSame(date('Y-m-d'), $user['date_of_birth']);
        self::assertTrue(password_verify(str_repeat('ž', 8), $user['password']));
    }

    #[DataProvider('invalidRegistrations')]
    public function testInvalidRegistrationDoesNotCreateAnyRecords(array $overrides): void
    {
        try {
            $this->register($overrides);
            self::fail('Invalid registration was accepted.');
        } catch (DomainException) {
            self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
            self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM accounts')->fetchColumn());
            self::assertFalse($this->db->inTransaction());
        }
    }

    public static function invalidRegistrations(): array
    {
        return [
            'empty first name' => [['firstName' => '']],
            'blank last name' => [['lastName' => '  ']],
            'long Unicode first name' => [['firstName' => str_repeat('Ž', 101)]],
            'long Unicode last name' => [['lastName' => str_repeat('ć', 101)]],
            'impossible date' => [['dateOfBirth' => '2001-02-29']],
            'invalid month' => [['dateOfBirth' => '2000-13-01']],
            'nonpadded date' => [['dateOfBirth' => '2000-2-01']],
            'date suffix' => [['dateOfBirth' => "2000-02-29\n"]],
            'outside SQL date range' => [['dateOfBirth' => '0999-01-01']],
            'future date' => [['dateOfBirth' => date('Y-m-d', strtotime('+1 day'))]],
            'invalid email' => [['email' => 'not-an-email']],
            'long email' => [['email' => str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 60) . '.com']],
            'short password' => [['password' => '1234567', 'passwordConfirmation' => '1234567']],
            'seven Unicode characters' => [['password' => str_repeat('ž', 7), 'passwordConfirmation' => str_repeat('ž', 7)]],
            'over bcrypt byte limit' => [['password' => str_repeat('a', 73), 'passwordConfirmation' => str_repeat('a', 73)]],
            'NUL password' => [['password' => "valid\0password", 'passwordConfirmation' => "valid\0password"]],
            'confirmation mismatch' => [['passwordConfirmation' => 'different-password']],
        ];
    }

    public function testDuplicateEmailRollsBackWithoutAnotherAccount(): void
    {
        $this->register();
        try {
            $this->register(['firstName' => 'Another']);
            self::fail('Duplicate email was accepted.');
        } catch (PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM accounts')->fetchColumn());
        self::assertSame('Željko', $this->db->query('SELECT first_name FROM users')->fetchColumn());
        self::assertFalse($this->db->inTransaction());
    }

    public function testInitialAccountFailureRollsBackTheNewUser(): void
    {
        $this->db->exec(
            'CREATE TRIGGER fail_account BEFORE INSERT ON accounts
             BEGIN SELECT RAISE(ABORT, "simulated account failure"); END'
        );
        try {
            $this->register();
            self::fail('Account failure was swallowed.');
        } catch (PDOException $e) {
            self::assertStringContainsString('simulated account failure', $e->getMessage());
        }

        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM accounts')->fetchColumn());
        self::assertFalse($this->db->inTransaction());
    }

    #[DataProvider('invalidPasswordChanges')]
    public function testInvalidPasswordChangePreservesExistingPassword(string $current, string $new, string $confirmation): void
    {
        $id = $this->register();
        $oldHash = $this->passwordHash($id);

        try {
            $this->users->changePassword($id, $current, $new, $confirmation);
            self::fail('Invalid password change was accepted.');
        } catch (DomainException) {
            self::assertSame($oldHash, $this->passwordHash($id));
        }
    }

    public static function invalidPasswordChanges(): array
    {
        return [
            'wrong current password' => ['wrong-password', 'new-password', 'new-password'],
            'confirmation mismatch' => ['original-password', 'new-password', 'different-password'],
            'short new password' => ['original-password', 'short', 'short'],
            'over bcrypt byte limit' => ['original-password', str_repeat('a', 73), str_repeat('a', 73)],
            'NUL password' => ['original-password', "valid\0password", "valid\0password"],
        ];
    }

    public function testPasswordChangeReplacesOnlyTheRequestedUsersPassword(): void
    {
        $id = $this->register();
        $otherId = $this->register(['email' => 'other@example.com']);
        $otherHash = $this->passwordHash($otherId);

        $this->users->changePassword($id, 'original-password', 'new-password', 'new-password');

        self::assertTrue(password_verify('new-password', $this->passwordHash($id)));
        self::assertFalse(password_verify('original-password', $this->passwordHash($id)));
        self::assertSame($otherHash, $this->passwordHash($otherId));
    }

    public function testAnotherUsersPasswordCannotAuthorizeAChange(): void
    {
        $id = $this->register();
        $otherId = $this->register([
            'email' => 'other@example.com', 'password' => 'other-password', 'passwordConfirmation' => 'other-password',
        ]);
        $oldHash = $this->passwordHash($id);
        $otherHash = $this->passwordHash($otherId);

        try {
            $this->users->changePassword($otherId, 'original-password', 'new-password', 'new-password');
            self::fail('Another users password was accepted.');
        } catch (DomainException) {
            self::assertSame($oldHash, $this->passwordHash($id));
            self::assertSame($otherHash, $this->passwordHash($otherId));
        }
    }

    public function testDisabledUserCannotChangePassword(): void
    {
        $id = $this->register();
        $oldHash = $this->passwordHash($id);
        $this->db->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$id]);

        try {
            $this->users->changePassword($id, 'original-password', 'new-password', 'new-password');
            self::fail('Disabled user changed their password.');
        } catch (DomainException) {
            self::assertSame($oldHash, $this->passwordHash($id));
        }
    }

    private function register(array $overrides = []): int
    {
        $data = array_replace(self::REGISTRATION, $overrides);
        $this->users->register(...$data);
        $statement = $this->db->prepare('SELECT id FROM users WHERE email = ?');
        $statement->execute([trim($data['email'])]);
        return (int) $statement->fetchColumn();
    }

    private function passwordHash(int $id): string
    {
        $statement = $this->db->prepare('SELECT password FROM users WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetchColumn();
    }
}
