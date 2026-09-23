<?php

namespace Tests\Unit;

use App\Models\Log;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;

final class LogTest extends TestCase
{
    private PDO $db;
    private string $directory;
    private string $filePath;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec(
            'CREATE TABLE activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER,
                action TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->directory = sys_get_temp_dir() . '/money-manager-log-test-' . bin2hex(random_bytes(8));
        $this->filePath = $this->directory . '/activity.log';
    }

    protected function tearDown(): void
    {
        foreach ([$this->filePath, $this->directory . '/errors.log'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testActionIsSavedToDatabaseAndAppendedToLogFile(): void
    {
        $log = new Log($this->db, $this->filePath);
        $log->record(42, 'Sačuvan budžet');
        $log->record(null, 'Sistemska aktivnost');

        $rows = $this->db->query('SELECT user_id, action FROM activity_logs ORDER BY id')->fetchAll();
        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        self::assertCount(2, $rows);
        foreach ($lines as $index => $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['timestamp', 'user_id', 'action'], array_keys($entry));
            self::assertNotFalse(DateTimeImmutable::createFromFormat(DATE_ATOM, $entry['timestamp']));
            unset($entry['timestamp']);
            self::assertSame($rows[$index], $entry);
        }
        self::assertStringContainsString('Sačuvan budžet', $lines[0]);
        self::assertSame(['user_id' => 42, 'action' => 'Sačuvan budžet'], $rows[0]);
    }

    public function testNewlinesAndMalformedUtf8CannotCreateExtraLogRecords(): void
    {
        $action = "Prva aktivnost\r\n{\"action\":\"forged\"}\n\xFF";
        (new Log($this->db, $this->filePath))->record(42, $action);

        $lines = file($this->filePath, FILE_IGNORE_NEW_LINES);
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("Prva aktivnost\r\n{\"action\":\"forged\"}\n\u{FFFD}", $entry['action']);
        self::assertSame(['timestamp', 'user_id', 'action'], array_keys($entry));
        self::assertSame($action, $this->db->query('SELECT action FROM activity_logs')->fetchColumn());
    }

    public function testFileFailurePreservesDatabaseRecordAndDoesNotPrintWarnings(): void
    {
        mkdir($this->directory);
        $previousErrorLog = ini_set('error_log', $this->directory . '/errors.log');
        ob_start();
        try {
            // An existing directory cannot be opened as an appendable file.
            (new Log($this->db, $this->directory))->record(42, 'Promenjena lozinka');
            self::assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
            ini_set('error_log', $previousErrorLog);
        }

        self::assertSame('Promenjena lozinka', $this->db->query('SELECT action FROM activity_logs')->fetchColumn());
        self::assertStringContainsString('Activity log file could not be written', file_get_contents($this->directory . '/errors.log'));
    }
}
