<?php

namespace Tests\Unit;

use App\Core\Auth;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    private array $previousSession;

    protected function setUp(): void
    {
        $this->previousSession = $_SESSION ?? [];
        $_SESSION = ['user' => ['id' => 42, 'role' => 'admin', 'active' => 1]];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->previousSession;
    }

    public function testRoleChangesApplyToAnExistingSession(): void
    {
        $currentUser = ['id' => 42, 'name' => 'User', 'email' => 'user@example.com', 'role' => 'user', 'active' => 1];
        $auth = $this->authWithDatabaseUser($currentUser);

        self::assertTrue($auth->check());
        self::assertSame('user', $auth->role());
        self::assertSame($currentUser, $auth->user());
    }

    public function testDisabledUserLosesExistingSession(): void
    {
        $auth = $this->authWithDatabaseUser(['id' => 42, 'role' => 'admin', 'active' => 0]);

        self::assertFalse($auth->check());
        self::assertNull($auth->id());
        self::assertSame('guest', $auth->role());
    }

    public function testDeletedUserLosesExistingSession(): void
    {
        $auth = $this->authWithDatabaseUser(false);

        self::assertFalse($auth->check());
        self::assertNull($auth->user());
    }

    public function testGuestDoesNotQueryDatabase(): void
    {
        $_SESSION = [];
        $db = $this->createMock(PDO::class);
        $db->expects(self::never())->method('prepare');

        self::assertFalse((new Auth($db))->check());
    }

    private function authWithDatabaseUser(array|false $user): Auth
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with([42])->willReturn(true);
        $statement->method('fetch')->willReturn($user);
        $db = $this->createMock(PDO::class);
        $db->expects(self::once())->method('prepare')->willReturn($statement);

        return new Auth($db);
    }
}
