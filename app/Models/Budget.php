<?php

namespace App\Models;

use DomainException;
use InvalidArgumentException;

final class Budget extends Model
{
    public function currentForUser(int $userId): array
    {
        $budgets = $this->query(
            'SELECT b.*, c.name AS category_name,
                    COALESCE(SUM(CASE WHEN t.type = "withdrawal" THEN t.amount ELSE 0 END), 0) AS spent
             FROM budgets b
             JOIN categories c ON c.id = b.category_id
             LEFT JOIN accounts a ON a.user_id = b.user_id
             LEFT JOIN transactions t ON t.account_id = a.id AND t.category_id = b.category_id
                 AND MONTH(t.created_at) = b.month AND YEAR(t.created_at) = b.year
             WHERE b.user_id = ? AND b.month = MONTH(CURRENT_DATE()) AND b.year = YEAR(CURRENT_DATE())
             GROUP BY b.id ORDER BY c.name',
            [$userId]
        );
        foreach ($budgets as &$budget) {
            $budget['remaining'] = BudgetCalculator::remaining((float) $budget['amount'], (float) $budget['spent']);
        }
        return $budgets;
    }

    public function pageForUser(int $userId, int $page = 1): array
    {
        return $this->paginate(
            'SELECT b.*, c.name AS category_name FROM budgets b
             JOIN categories c ON c.id = b.category_id WHERE b.user_id = ?
             ORDER BY b.year DESC, b.month DESC, b.id DESC',
            'SELECT COUNT(*) FROM budgets WHERE user_id = ?',
            [$userId],
            $page
        );
    }

    public function save(int $userId, int $categoryId, float $amount, int $month, int $year, int $createdBy): void
    {
        try {
            $amount = Amount::validate($amount);
        } catch (InvalidArgumentException) {
            throw new DomainException('Podaci budžeta nisu ispravni.');
        }
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) {
            throw new DomainException('Podaci budžeta nisu ispravni.');
        }

        $this->db->prepare(
            'INSERT INTO budgets (user_id, category_id, amount, month, year, created_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), created_by = VALUES(created_by)'
        )->execute([$userId, $categoryId, $amount, $month, $year, $createdBy]);
    }

    public function delete(int $userId, int $budgetId): void
    {
        $this->db->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?')->execute([$budgetId, $userId]);
    }
}
