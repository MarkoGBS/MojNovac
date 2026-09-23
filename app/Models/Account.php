<?php

namespace App\Models;

use DomainException;

final class Account extends Model
{
    public function forUser(int $userId): array
    {
        return $this->query(
            'SELECT * FROM accounts WHERE user_id = ? AND active = 1 ORDER BY id',
            [$userId]
        );
    }

    public function transactionPage(int $userId, int $page = 1): array
    {
        return $this->paginate(
            'SELECT t.*, a.name AS account_name, c.name AS category_name,
                    da.name AS destination_name
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             LEFT JOIN accounts da ON da.id = t.destination_account_id
             LEFT JOIN categories c ON c.id = t.category_id
             WHERE a.user_id = ? ORDER BY t.created_at DESC, t.id DESC',
            'SELECT COUNT(*) FROM transactions t
             JOIN accounts a ON a.id = t.account_id WHERE a.user_id = ?',
            [$userId],
            $page
        );
    }

    public function create(int $userId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('Naziv računa je obavezan.');
        }
        $this->db->prepare('INSERT INTO accounts (user_id, name, balance) VALUES (?, ?, 0)')
            ->execute([$userId, $name]);
    }
}
