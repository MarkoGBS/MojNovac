<?php

namespace Tests\Unit;

use App\Models\SupportTicket;
use DomainException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SupportTicketTest extends TestCase
{
    private PDO $db;
    private SupportTicket $tickets;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA foreign_keys = ON');
        $this->db->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, role TEXT NOT NULL, active INTEGER NOT NULL)'
        );
        $this->db->exec(
            'CREATE TABLE manager_assignments (
                manager_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
                user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(manager_id, user_id)
            )'
        );
        $this->db->exec(
            'CREATE TABLE support_tickets (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id),
                subject TEXT NOT NULL, message TEXT NOT NULL, reply TEXT,
                replied_by INTEGER REFERENCES users(id) ON DELETE SET NULL, replied_at TEXT,
                status TEXT NOT NULL DEFAULT "open", created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->db->exec(
            'INSERT INTO users (id, name, role, active) VALUES
                (1, "First user", "user", 1), (2, "Second user", "user", 1),
                (3, "First manager", "manager", 1), (4, "Second manager", "manager", 1),
                (5, "Disabled manager", "manager", 0), (6, "Administrator", "admin", 1)'
        );
        $this->db->exec('INSERT INTO manager_assignments (manager_id, user_id) VALUES (3, 1), (4, 2), (5, 1), (6, 1)');
        $this->tickets = new SupportTicket($this->db);
        $this->tickets->create(1, 'First question', 'First message');
        $this->tickets->create(2, 'Second question', 'Second message');
    }

    public function testAssignedManagerCanReplyAndEditTheSavedReply(): void
    {
        $this->tickets->reply(1, 1, 3, "  Prvi odgovor\nDrugi red  ");
        $ticket = $this->ticket(1);

        self::assertSame("Prvi odgovor\nDrugi red", $ticket['reply']);
        self::assertSame(3, $ticket['replied_by']);
        self::assertNotEmpty($ticket['replied_at']);
        self::assertSame('resolved', $ticket['status']);
        self::assertSame('First question', $ticket['subject']);
        self::assertSame('First message', $ticket['message']);
        self::assertNull($this->ticket(2)['reply']);

        $this->tickets->reply(1, 1, 3, 'Izmenjen odgovor');
        $this->tickets->reply(1, 1, 3, 'Izmenjen odgovor');

        self::assertSame('Izmenjen odgovor', $this->ticket(1)['reply']);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn());
    }

    #[DataProvider('unauthorizedReplies')]
    public function testUnauthorizedOrForgedReplyCannotModifyAnyTicket(int $ticketId, int $userId, int $managerId): void
    {
        $before = $this->db->query('SELECT * FROM support_tickets ORDER BY id')->fetchAll();
        try {
            $this->tickets->reply($ticketId, $userId, $managerId, 'Unauthorized reply');
            self::fail('Unauthorized reply was accepted.');
        } catch (DomainException) {
            self::assertSame($before, $this->db->query('SELECT * FROM support_tickets ORDER BY id')->fetchAll());
        }
    }

    public static function unauthorizedReplies(): array
    {
        return [
            'another users ticket on assigned user path' => [2, 1, 3],
            'another user without assignment' => [2, 2, 3],
            'unassigned manager' => [1, 1, 4],
            'missing ticket' => [999, 1, 3],
            'disabled manager' => [1, 1, 5],
            'nonmanager role' => [1, 1, 6],
            'incorrect owner' => [1, 2, 3],
        ];
    }

    #[DataProvider('invalidReplies')]
    public function testInvalidReplyLeavesTheTicketOpen(string $reply): void
    {
        try {
            $this->tickets->reply(1, 1, 3, $reply);
            self::fail('Invalid reply was accepted.');
        } catch (DomainException) {
            self::assertNull($this->ticket(1)['reply']);
            self::assertSame('open', $this->ticket(1)['status']);
        }
    }

    public static function invalidReplies(): array
    {
        return [
            'empty' => [''],
            'whitespace' => [" \t\n "],
            'too many Unicode characters' => [str_repeat('Ž', 5001)],
        ];
    }

    public function testUnicodeLimitAndZeroReplyAreAccepted(): void
    {
        $reply = str_repeat('Ž', 5000);
        $this->tickets->reply(1, 1, 3, $reply);
        self::assertSame($reply, $this->ticket(1)['reply']);

        $this->tickets->reply(1, 1, 3, '0');
        self::assertSame('0', $this->ticket(1)['reply']);
        self::assertSame('resolved', $this->ticket(1)['status']);
    }

    public function testRemovingAssignmentPreventsEditingAnExistingReply(): void
    {
        $this->tickets->reply(1, 1, 3, 'Original reply');
        $this->db->exec('DELETE FROM manager_assignments WHERE manager_id = 3 AND user_id = 1');

        try {
            $this->tickets->reply(1, 1, 3, 'Unauthorized edit');
            self::fail('Removed assignment still allowed a reply.');
        } catch (DomainException) {
            self::assertSame('Original reply', $this->ticket(1)['reply']);
        }
    }

    public function testDeletingReplyAuthorPreservesTheReply(): void
    {
        $this->tickets->reply(1, 1, 3, 'Saved answer');
        $this->db->exec('DELETE FROM users WHERE id = 3');
        $ticket = $this->tickets->pageForUser(1)['items'][0];

        self::assertSame('Saved answer', $ticket['reply']);
        self::assertNull($ticket['replied_by']);
        self::assertNull($ticket['replied_by_name']);
    }

    public function testUserPaginationContainsOnlyThatUsersTicketsWithStableOrdering(): void
    {
        for ($i = 0; $i < 22; $i++) {
            $this->tickets->create(1, 'Question ' . $i, 'Message ' . $i);
        }
        $ids = [];
        for ($pageNumber = 1; $pageNumber <= 3; $pageNumber++) {
            $page = $this->tickets->pageForUser(1, $pageNumber);
            self::assertSame(23, $page['total']);
            self::assertSame(3, $page['pages']);
            self::assertSame($pageNumber, $page['page']);
            self::assertCount($pageNumber === 3 ? 3 : 10, $page['items']);
            foreach ($page['items'] as $ticket) {
                self::assertSame(1, $ticket['user_id']);
                $ids[] = $ticket['id'];
            }
        }

        self::assertCount(23, array_unique($ids));
        $descending = $ids;
        rsort($descending);
        self::assertSame($descending, $ids);
        self::assertSame(3, $this->tickets->pageForUser(1, 999)['page']);
        self::assertSame(0, $this->tickets->pageForUser(999)['total']);
    }

    public function testAdminPageIncludesOwnerAndReplyAuthor(): void
    {
        $this->tickets->reply(1, 1, 3, 'Visible answer');
        $page = $this->tickets->page();
        $byId = array_column($page['items'], null, 'id');

        self::assertSame(2, $page['total']);
        self::assertSame('First user', $byId[1]['user_name']);
        self::assertSame('First manager', $byId[1]['replied_by_name']);
        self::assertSame('Visible answer', $byId[1]['reply']);
        self::assertSame('Second user', $byId[2]['user_name']);
        self::assertNull($byId[2]['reply']);
    }

    private function ticket(int $id): array
    {
        $statement = $this->db->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch();
    }
}
