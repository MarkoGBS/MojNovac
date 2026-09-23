<?php

namespace App\Models;

use PDO;

final class Log extends Model
{
    private string $filePath;

    public function __construct(PDO $db, ?string $filePath = null)
    {
        parent::__construct($db);
        $this->filePath = $filePath ?? dirname(__DIR__, 2) . '/storage/logs/activity.log';
    }

    public function activitiesPage(?int $userId = null, int $page = 1): array
    {
        $where = $userId === null ? '' : ' WHERE al.user_id = ?';
        return $this->paginate(
            'SELECT al.*, u.name AS user_name FROM activity_logs al
             LEFT JOIN users u ON u.id = al.user_id' . $where . ' ORDER BY al.created_at DESC, al.id DESC',
            'SELECT COUNT(*) FROM activity_logs al' . $where,
            $userId === null ? [] : [$userId],
            $page
        );
    }

    public function record(?int $userId, string $action): void
    {
        $this->db->prepare('INSERT INTO activity_logs (user_id, action) VALUES (?, ?)')
            ->execute([$userId, $action]);

        $directory = dirname($this->filePath);
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            error_log('Activity log file could not be written: unable to create log directory.');
            return;
        }

        $entry = json_encode([
            'timestamp' => date(DATE_ATOM),
            'user_id' => $userId,
            'action' => $action,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        if (@file_put_contents($this->filePath, $entry, FILE_APPEND | LOCK_EX) !== strlen($entry)) {
            error_log('Activity log file could not be written; the action remains recorded in the database.');
        }
    }

    public function deliveryFailure(string $type, ?string $recipient, string $error): void
    {
        $this->db->prepare(
            'INSERT INTO delivery_logs (type, recipient, status, error_message) VALUES (?, ?, "failed", ?)'
        )->execute([$type, $recipient, $error]);
    }
}
