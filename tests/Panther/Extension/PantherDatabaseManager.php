<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\Extension;

use PDO;

final class PantherDatabaseManager
{
    private static null|self $instance = null;

    private const DATABASE_URL_FILE = __DIR__ . '/../../../var/panther_db_url.txt';
    private const TEMPLATE_DB = 'speedpuzzling_test_template';

    private null|PDO $pdo = null;
    private null|string $currentDatabase = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function createDatabaseForTest(string $testId): void
    {
        $pdo = $this->getAdminConnection();

        // Generate unique database name
        $hash = substr(md5($testId . microtime(true) . getmypid()), 0, 12);
        $this->currentDatabase = 'speedpuzzling_panther_' . $hash;

        // Terminate any lingering connections to template
        $pdo->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
            WHERE datname = '" . self::TEMPLATE_DB . "' AND pid <> pg_backend_pid()"
        );

        // Create database from template (fast operation ~50ms)
        $pdo->exec("CREATE DATABASE \"{$this->currentDatabase}\" TEMPLATE \"" . self::TEMPLATE_DB . "\"");

        // Write DATABASE_URL to shared file
        $databaseUrl = sprintf(
            'postgresql://postgres:postgres@postgres:5432/%s?serverVersion=16&charset=utf8',
            $this->currentDatabase
        );

        file_put_contents(self::DATABASE_URL_FILE, $databaseUrl);
    }

    public function dropCurrentDatabase(): void
    {
        if ($this->currentDatabase === null) {
            return;
        }

        $pdo = $this->getAdminConnection();

        // Terminate all connections
        $pdo->exec(
            "SELECT pg_terminate_backend(pid) FROM pg_stat_activity
            WHERE datname = '{$this->currentDatabase}' AND pid <> pg_backend_pid()"
        );

        // Drop database
        $pdo->exec("DROP DATABASE IF EXISTS \"{$this->currentDatabase}\"");

        // Clean up file
        if (file_exists(self::DATABASE_URL_FILE)) {
            unlink(self::DATABASE_URL_FILE);
        }

        $this->currentDatabase = null;
    }

    private function getAdminConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                'pgsql:host=postgres;port=5432;dbname=postgres',
                'postgres',
                'postgres',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return $this->pdo;
    }
}
