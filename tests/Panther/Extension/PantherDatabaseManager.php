<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther\Extension;

use PDO;

final class PantherDatabaseManager
{
    private static null|self $instance = null;

    private const DATABASE_URL_FILE = __DIR__ . '/../../../var/panther_db_url.txt';

    private null|PDO $pdo = null;
    private null|string $currentDatabase = null;
    private string $templateDb;

    /** @var array{host: string, port: int, user: string, password: string, dbname: string} */
    private array $dbConfig;

    private function __construct()
    {
        $this->dbConfig = self::parseDatabaseUrl();
        $this->templateDb = $this->dbConfig['dbname'] . '_template';
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
            WHERE datname = '{$this->templateDb}' AND pid <> pg_backend_pid()"
        );

        // Create database from template (fast operation ~50ms)
        $pdo->exec("CREATE DATABASE \"{$this->currentDatabase}\" TEMPLATE \"{$this->templateDb}\"");

        // Write DATABASE_URL to shared file using same host/port as original
        $databaseUrl = sprintf(
            'postgresql://%s:%s@%s:%d/%s?serverVersion=16&charset=utf8',
            $this->dbConfig['user'],
            $this->dbConfig['password'],
            $this->dbConfig['host'],
            $this->dbConfig['port'],
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
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=postgres',
                $this->dbConfig['host'],
                $this->dbConfig['port']
            );

            $this->pdo = new PDO(
                $dsn,
                $this->dbConfig['user'],
                $this->dbConfig['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return $this->pdo;
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, dbname: string}
     */
    private static function parseDatabaseUrl(): array
    {
        $databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');

        if (!is_string($databaseUrl) || $databaseUrl === '') {
            // Fallback for Docker environment
            return [
                'host' => 'postgres',
                'port' => 5432,
                'user' => 'postgres',
                'password' => 'postgres',
                'dbname' => 'speedpuzzling_test',
            ];
        }

        $parsed = parse_url($databaseUrl);
        $dbname = isset($parsed['path']) ? ltrim($parsed['path'], '/') : 'speedpuzzling_test';

        return [
            'host' => $parsed['host'] ?? 'postgres',
            'port' => $parsed['port'] ?? 5432,
            'user' => $parsed['user'] ?? 'postgres',
            'password' => $parsed['pass'] ?? 'postgres',
            'dbname' => $dbname,
        ];
    }
}
