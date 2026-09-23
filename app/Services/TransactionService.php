<?php

namespace App\Services;

use App\Models\Amount;
use DomainException;
use PDO;
use Throwable;

final class TransactionService
{
    public function __construct(private PDO $db)
    {
    }

    public function deposit(int $userId, int $accountId, float $amount, ?int $categoryId = null): void
    {
        $amount = Amount::validate($amount);
        $this->changeBalance($userId, $accountId, $amount, 'deposit', $categoryId);
    }

    public function withdraw(int $userId, int $accountId, float $amount, ?int $categoryId = null): void
    {
        $amount = Amount::validate($amount);
        $this->changeBalance($userId, $accountId, -$amount, 'withdrawal', $categoryId);
    }

    private function changeBalance(
        int $userId,
        int $accountId,
        float $change,
        string $type,
        ?int $categoryId
    ): void {
        $this->db->beginTransaction();
        try {
            $account = $this->ownedAccount($userId, $accountId);
            $newBalance = (float) $account['balance'] + $change;
            if ($newBalance < 0) {
                throw new DomainException('Nema dovoljno novca na računu.');
            }

            $this->db->prepare('UPDATE accounts SET balance = ? WHERE id = ?')
                ->execute([$newBalance, $accountId]);
            $this->db->prepare(
                'INSERT INTO transactions (account_id, category_id, type, amount) VALUES (?, ?, ?, ?)'
            )->execute([$accountId, $categoryId ?: null, $type, abs($change)]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function transfer(int $userId, int $sourceId, int $destinationId, float $amount): void
    {
        $amount = Amount::validate($amount);
        if ($sourceId === $destinationId) {
            throw new DomainException('Izaberite dva različita računa.');
        }

        $this->db->beginTransaction();
        try {
            $source = $this->ownedAccount($userId, $sourceId);
            $this->ownedAccount($userId, $destinationId);
            if ((float) $source['balance'] < $amount) {
                throw new DomainException('Nema dovoljno novca na računu.');
            }

            $this->db->prepare('UPDATE accounts SET balance = balance - ? WHERE id = ?')
                ->execute([$amount, $sourceId]);
            $this->db->prepare('UPDATE accounts SET balance = balance + ? WHERE id = ?')
                ->execute([$amount, $destinationId]);
            $this->db->prepare(
                'INSERT INTO transactions (account_id, destination_account_id, type, amount)
                 VALUES (?, ?, "transfer", ?)'
            )->execute([$sourceId, $destinationId, $amount]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function ownedAccount(int $userId, int $accountId): array
    {
        $sql = 'SELECT * FROM accounts WHERE id = ? AND user_id = ? AND active = 1';
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute([$accountId, $userId]);
        $account = $statement->fetch();

        if (!$account) {
            throw new DomainException('Račun nije pronađen.');
        }
        return $account;
    }
}
