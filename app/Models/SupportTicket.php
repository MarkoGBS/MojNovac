<?php

namespace App\Models;

use DomainException;

final class SupportTicket extends Model
{
    public function page(int $page = 1): array
    {
        return $this->paginate(
            'SELECT st.*, u.name AS user_name, r.name AS replied_by_name FROM support_tickets st
             JOIN users u ON u.id = st.user_id LEFT JOIN users r ON r.id = st.replied_by
             ORDER BY st.created_at DESC, st.id DESC',
            'SELECT COUNT(*) FROM support_tickets',
            [],
            $page
        );
    }

    public function pageForUser(int $userId, int $page = 1): array
    {
        return $this->paginate(
            'SELECT st.*, u.name AS user_name, r.name AS replied_by_name FROM support_tickets st
             JOIN users u ON u.id = st.user_id LEFT JOIN users r ON r.id = st.replied_by
             WHERE st.user_id = ? ORDER BY st.created_at DESC, st.id DESC',
            'SELECT COUNT(*) FROM support_tickets WHERE user_id = ?',
            [$userId],
            $page
        );
    }

    public function create(int $userId, string $subject, string $message): void
    {
        $subject = trim($subject);
        $message = trim($message);
        if ($subject === '' || $message === '') {
            throw new DomainException('Naslov i poruka su obavezni.');
        }

        $this->db->prepare('INSERT INTO support_tickets (user_id, subject, message) VALUES (?, ?, ?)')
            ->execute([$userId, $subject, $message]);
    }

    public function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
            throw new DomainException('Nepoznat status.');
        }

        $this->db->prepare('UPDATE support_tickets SET status = ? WHERE id = ?')->execute([$status, $id]);
    }

    public function reply(int $ticketId, int $userId, int $managerId, string $reply): void
    {
        $reply = trim($reply);
        if ($reply === '' || mb_strlen($reply, 'UTF-8') > 5000) {
            throw new DomainException('Odgovor je obavezan i može imati najviše 5000 znakova.');
        }

        $scope = 'id = ? AND user_id = ? AND EXISTS (
            SELECT 1 FROM manager_assignments ma JOIN users m ON m.id = ma.manager_id
            WHERE ma.user_id = support_tickets.user_id AND ma.manager_id = ?
                AND m.role = "manager" AND m.active = 1
        )';
        $statement = $this->db->prepare(
            'UPDATE support_tickets SET reply = ?, replied_by = ?, replied_at = CURRENT_TIMESTAMP,
             status = "resolved" WHERE ' . $scope
        );
        $statement->execute([$reply, $managerId, $ticketId, $userId, $managerId]);
        if ($statement->rowCount() === 0
            && !$this->query('SELECT id FROM support_tickets WHERE ' . $scope, [$ticketId, $userId, $managerId])) {
            throw new DomainException('Zahtev za podršku nije pronađen ili vam korisnik nije dodeljen.');
        }
    }
}
