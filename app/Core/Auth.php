<?php

namespace App\Core;

use App\Models\Log;
use PDO;

final class Auth
{
    public function __construct(private PDO $db)
    {
        if (!isset($_SESSION['user'])) {
            return;
        }

        $statement = $this->db->prepare(
            'SELECT id, name, email, role, active FROM users WHERE id = ? LIMIT 1'
        );
        $statement->execute([$this->id()]);
        $user = $statement->fetch();

        if (!$user || !$user['active']) {
            unset($_SESSION['user']);
            return;
        }

        $_SESSION['user'] = $user;
    }

    public function attempt(string $email, string $password): bool
    {
        $statement = $this->db->prepare(
            'SELECT id, name, email, password, role, active FROM users WHERE email = ? LIMIT 1'
        );
        $statement->execute([$email]);
        $user = $statement->fetch();

        if (!$user || !$user['active'] || !password_verify($password, $user['password'])) {
            $this->log(null, 'Neuspešna prijava');
            return false;
        }

        unset($user['password']);
        $_SESSION['user'] = $user;
        session_regenerate_id(true);
        $this->log((int) $user['id'], 'Prijava na sistem');
        return true;
    }

    public function logout(): void
    {
        if ($this->check()) {
            $this->log((int) $_SESSION['user']['id'], 'Odjava sa sistema');
        }
        unset($_SESSION['user']);
        session_regenerate_id(true);
    }

    public function check(): bool
    {
        return isset($_SESSION['user']);
    }

    public function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    public function id(): ?int
    {
        return isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;
    }

    public function role(): string
    {
        return $_SESSION['user']['role'] ?? 'guest';
    }

    public function requireRole(string ...$roles): void
    {
        if (!$this->check()) {
            $_SESSION['flash_error'] = 'Prvo se prijavite.';
            header('Location: ' . url('/login'));
            exit;
        }

        if (!in_array($this->role(), $roles, true)) {
            http_response_code(403);
            echo '403 - Nemate pravo pristupa ovoj stranici.';
            exit;
        }
    }

    public function log(?int $userId, string $action): void
    {
        (new Log($this->db))->record($userId, $action);
    }
}
