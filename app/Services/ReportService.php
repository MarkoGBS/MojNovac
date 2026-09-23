<?php

namespace App\Services;

use App\Models\BudgetCalculator;
use PDO;

final class ReportService
{
    public function __construct(private PDO $db)
    {
    }

    public function make(string $type): array
    {
        return match ($type) {
            'transactions' => $this->transactions(),
            'monthly' => $this->monthly(),
            'budgets' => $this->budgets(),
            default => throw new \InvalidArgumentException('Nepoznat izveštaj.'),
        };
    }

    private function transactions(): array
    {
        return [
            'title' => 'Izveštaj o transakcijama',
            'headers' => ['Datum', 'Korisnik', 'Račun', 'Tip', 'Kategorija', 'Iznos (RSD)'],
            'rows' => $this->rows(
                'SELECT DATE_FORMAT(t.created_at, "%d.%m.%Y %H:%i") AS datum, u.name AS korisnik,
                        a.name AS racun, t.type AS tip, COALESCE(c.name, "-") AS kategorija,
                        t.amount AS iznos
                 FROM transactions t JOIN accounts a ON a.id = t.account_id
                 JOIN users u ON u.id = a.user_id LEFT JOIN categories c ON c.id = t.category_id
                 ORDER BY t.created_at DESC'
            ),
        ];
    }

    private function monthly(): array
    {
        return [
            'title' => 'Mesečni prihodi i rashodi',
            'headers' => ['Mesec', 'Prihodi (RSD)', 'Rashodi (RSD)', 'Razlika (RSD)'],
            'rows' => $this->rows(
                'SELECT DATE_FORMAT(t.created_at, "%Y-%m") AS mesec,
                        SUM(CASE WHEN t.type = "deposit" THEN t.amount ELSE 0 END) AS prihodi,
                        SUM(CASE WHEN t.type = "withdrawal" THEN t.amount ELSE 0 END) AS rashodi,
                        SUM(CASE WHEN t.type = "deposit" THEN t.amount
                                 WHEN t.type = "withdrawal" THEN -t.amount ELSE 0 END) AS razlika
                 FROM transactions t GROUP BY DATE_FORMAT(t.created_at, "%Y-%m") ORDER BY mesec DESC'
            ),
        ];
    }

    private function budgets(): array
    {
        $rows = $this->rows(
            'SELECT u.name AS korisnik, CONCAT(LPAD(b.month, 2, "0"), "/", b.year) AS period,
                    c.name AS kategorija, b.amount AS budzet,
                    COALESCE(SUM(CASE WHEN t.type = "withdrawal" THEN t.amount ELSE 0 END), 0) AS potroseno
             FROM budgets b JOIN users u ON u.id = b.user_id JOIN categories c ON c.id = b.category_id
             LEFT JOIN accounts a ON a.user_id = b.user_id
             LEFT JOIN transactions t ON t.account_id = a.id AND t.category_id = b.category_id
                AND MONTH(t.created_at) = b.month AND YEAR(t.created_at) = b.year
             GROUP BY b.id ORDER BY b.year DESC, b.month DESC, u.name'
        );
        foreach ($rows as &$row) {
            $row['preostalo'] = BudgetCalculator::remaining((float) $row['budzet'], (float) $row['potroseno']);
            $row['procenat'] = BudgetCalculator::percentage((float) $row['budzet'], (float) $row['potroseno']);
        }

        return [
            'title' => 'Budžet prema ostvarenoj potrošnji',
            'headers' => ['Korisnik', 'Period', 'Kategorija', 'Budžet', 'Potrošeno', 'Preostalo', '%'],
            'rows' => $rows,
        ];
    }

    private function rows(string $sql): array
    {
        return $this->db->query($sql)->fetchAll();
    }
}
