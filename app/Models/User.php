<?php

namespace App\Models;

use DateTimeImmutable;
use DomainException;
use PDOException;
use Throwable;

final class User extends Model
{
    public function all(): array
    {
        return $this->query('SELECT id, name, email, role, active, created_at FROM users ORDER BY id');
    }

    public function page(int $page = 1): array
    {
        return $this->paginate(
            'SELECT id, name, email, role, active, created_at FROM users ORDER BY id',
            'SELECT COUNT(*) FROM users',
            [],
            $page
        );
    }

    public function activeManagers(): array
    {
        return $this->query('SELECT id, name FROM users WHERE role = "manager" AND active = 1 ORDER BY name');
    }

    public function assignmentsPage(int $page = 1): array
    {
        return $this->paginate(
            'SELECT ma.id, m.name AS manager_name, u.name AS user_name
             FROM manager_assignments ma JOIN users m ON m.id = ma.manager_id
             JOIN users u ON u.id = ma.user_id ORDER BY m.name, u.name, ma.id',
            'SELECT COUNT(*) FROM manager_assignments ma
             JOIN users m ON m.id = ma.manager_id JOIN users u ON u.id = ma.user_id',
            [],
            $page
        );
    }

    public function assignedPage(int $managerId, int $page = 1): array
    {
        return $this->paginate(
            'SELECT u.id, u.name, u.email, COALESCE(SUM(a.balance), 0) AS total_balance
             FROM manager_assignments ma
             JOIN users u ON u.id = ma.user_id
             LEFT JOIN accounts a ON a.user_id = u.id AND a.active = 1
             WHERE ma.manager_id = ? GROUP BY u.id ORDER BY u.name, u.id',
            'SELECT COUNT(*) FROM manager_assignments ma
             JOIN users u ON u.id = ma.user_id WHERE ma.manager_id = ?',
            [$managerId],
            $page
        );
    }

    public function findAssigned(int $userId, int $managerId): ?array
    {
        $rows = $this->query(
            'SELECT u.* FROM users u JOIN manager_assignments ma ON ma.user_id = u.id
             WHERE u.id = ? AND ma.manager_id = ?',
            [$userId, $managerId]
        );
        return $rows[0] ?? null;
    }

    public function updateAccess(int $userId, string $role, bool $active, int $actingUserId): void
    {
        if (!in_array($role, ['user', 'manager', 'admin'], true)) {
            throw new DomainException('Nepoznata uloga.');
        }
        if ($userId === $actingUserId && (!$active || $role !== 'admin')) {
            throw new DomainException('Ne možete ukloniti sopstveni administratorski pristup.');
        }
        $this->db->prepare('UPDATE users SET role = ?, active = ? WHERE id = ?')
            ->execute([$role, $active ? 1 : 0, $userId]);
    }

    public function assignManager(int $managerId, int $userId): void
    {
        $check = $this->query('SELECT id FROM users WHERE id = ? AND role = "manager"', [$managerId]);
        if (!$check || $managerId === $userId) {
            throw new DomainException('Izaberite ispravnog menadžera i korisnika.');
        }
        $this->db->prepare(
            'INSERT IGNORE INTO manager_assignments (manager_id, user_id) VALUES (?, ?)'
        )->execute([$managerId, $userId]);
    }

    public function deleteAssignment(int $id): void
    {
        $this->db->prepare('DELETE FROM manager_assignments WHERE id = ?')->execute([$id]);
    }

    public function register(
        string $firstName,
        string $lastName,
        string $dateOfBirth,
        string $email,
        string $password,
        string $passwordConfirmation
    ): int {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        $email = trim($email);
        if ($firstName === '' || $lastName === ''
            || mb_strlen($firstName, 'UTF-8') > 100 || mb_strlen($lastName, 'UTF-8') > 100) {
            throw new DomainException('Ime i prezime su obavezni i mogu imati najviše 100 znakova.');
        }
        $birthDate = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $dateOfBirth)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth) : false;
        if (!$birthDate || $birthDate->format('Y-m-d') !== $dateOfBirth
            || (int) $birthDate->format('Y') < 1000 || $birthDate > new DateTimeImmutable('today')) {
            throw new DomainException('Unesite ispravan datum rođenja koji nije u budućnosti.');
        }
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Unesite ispravnu email adresu (najviše 190 znakova).');
        }
        $this->validatePassword($password, $passwordConfirmation);

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO users (name, first_name, last_name, date_of_birth, email, password, role)
                 VALUES (?, ?, ?, ?, ?, ?, "user")'
            )->execute([
                $firstName . ' ' . $lastName, $firstName, $lastName, $dateOfBirth,
                $email, password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $this->db->lastInsertId();
            (new Account($this->db))->create($userId, 'Glavni račun');
            $this->db->commit();
            return $userId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($e instanceof PDOException && ($e->errorInfo[1] ?? null) === 1062) {
                throw new DomainException('Email adresa je već zauzeta.', 0, $e);
            }
            throw $e;
        }
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $confirmation): void
    {
        $users = $this->query('SELECT password FROM users WHERE id = ? AND active = 1 LIMIT 1', [$userId]);
        if (!$users) {
            throw new DomainException('Korisnički nalog nije dostupan.');
        }
        $currentHash = $users[0]['password'];
        if (!password_verify($currentPassword, $currentHash)) {
            throw new DomainException('Trenutna lozinka nije ispravna.');
        }
        $this->validatePassword($newPassword, $confirmation);

        $statement = $this->db->prepare('UPDATE users SET password = ? WHERE id = ? AND password = ? AND active = 1');
        $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId, $currentHash]);
        if ($statement->rowCount() !== 1) {
            throw new DomainException('Lozinka je u međuvremenu promenjena. Pokušajte ponovo.');
        }
    }

    private function validatePassword(string $password, string $confirmation): void
    {
        if (mb_strlen($password, 'UTF-8') < 8) {
            throw new DomainException('Lozinka mora imati najmanje 8 znakova.');
        }
        if (strlen($password) > 72) {
            throw new DomainException('Lozinka je predugačka. Unesite kraću lozinku.');
        }
        if (str_contains($password, "\0")) {
            throw new DomainException('Lozinka sadrži nedozvoljen znak.');
        }
        if ($password !== $confirmation) {
            throw new DomainException('Lozinke se ne poklapaju.');
        }
    }
}
