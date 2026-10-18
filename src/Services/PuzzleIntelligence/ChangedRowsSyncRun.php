<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;

/**
 * One sync of ChangedRowsSync: add() the fresh rows, then finish() - or sync() scope by scope (a chunk of players at a
 * time) and finish() the last one. Not a service: it lives for one batch step.
 */
final class ChangedRowsSyncRun
{
    /** Rows per statement when writing or deleting */
    private const int CHUNK = 5000;

    private const array SQL_TYPES = ['uuid', 'integer', 'smallint', 'double precision', 'boolean', 'varchar', 'timestamp', 'jsonb'];

    /** @var list<array<string, mixed>> */
    private array $pending = [];

    /** @var array<string, string> column => SQL type, keys first */
    private readonly array $columns;

    private int $written = 0;

    private int $deleted = 0;

    /**
     * @param array<string, string> $keyColumns
     * @param array<string, string> $valueColumns
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly array $keyColumns,
        private readonly array $valueColumns,
    ) {
        $this->columns = [...$keyColumns, ...$valueColumns];

        assert(preg_match('/^[a-z_][a-z0-9_]*$/', $table) === 1);

        foreach ($this->columns as $column => $type) {
            assert(preg_match('/^[a-z_][a-z0-9_]*$/', $column) === 1, "Column name {$column}");
            assert(in_array($type, self::SQL_TYPES, true), "SQL type {$type}");
        }
    }

    /**
     * @param array<string, mixed> $row every key and value column; a jsonb value as the PHP value to encode
     */
    public function add(array $row): void
    {
        $this->pending[] = $row;
    }

    /**
     * Writes the difference between the rows added since the last sync and the stored rows of $scope.
     *
     * @param string $scope SQL condition on the table's columns: which stored rows the added rows replace - a stored
     *                      row in scope that was not added is deleted
     * @param array<string, mixed> $parameters for $scope
     * @param array<string, \Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType|string> $types for $parameters
     */
    public function sync(\DateTimeImmutable $now, string $scope = 'TRUE', array $parameters = [], array $types = []): void
    {
        $columnList = implode(', ', array_keys($this->columns));
        $stored = [];
        $rows = $this->connection->executeQuery("SELECT {$columnList} FROM {$this->table} WHERE {$scope}", $parameters, $types);

        while (($row = $rows->fetchAssociative()) !== false) {
            $stored[$this->key($row)] = $this->values($row);
        }

        $changed = [];

        foreach ($this->pending as $row) {
            $key = $this->key($row);

            if (!array_key_exists($key, $stored) || $stored[$key] !== $this->values($row)) {
                $changed[] = $row;
            }

            unset($stored[$key]);
        }

        $this->pending = [];
        $gone = array_keys($stored);

        if ($changed === [] && $gone === []) {
            return;
        }

        $this->connection->beginTransaction();

        try {
            foreach (array_chunk($changed, self::CHUNK) as $chunk) {
                $this->written += $this->upsert($chunk, $now);
            }

            foreach (array_chunk($gone, self::CHUNK) as $chunk) {
                $this->deleted += $this->delete($chunk);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, \Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType|string> $types
     * @return array{written: int, deleted: int} rows inserted or updated, rows deleted - by every sync of this run
     */
    public function finish(\DateTimeImmutable $now, string $scope = 'TRUE', array $parameters = [], array $types = []): array
    {
        $this->sync($now, $scope, $parameters, $types);

        return ['written' => $this->written, 'deleted' => $this->deleted];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsert(array $rows, \DateTimeImmutable $now): int
    {
        $arguments = [];
        $parameters = ['now' => $now->format('Y-m-d H:i:s')];

        foreach ($this->columns as $column => $type) {
            $arguments[] = "CAST(:{$column} AS {$type}[])";
            $parameters[$column] = self::arrayLiteral(array_column($rows, $column), $type);
        }

        $columnList = implode(', ', array_keys($this->columns));
        $conflictKey = implode(', ', array_keys($this->keyColumns));
        $updates = implode(', ', array_map(
            static fn (string $column): string => "{$column} = EXCLUDED.{$column}",
            [...array_keys($this->valueColumns), 'computed_at'],
        ));

        return (int) $this->connection->executeStatement(
            "INSERT INTO {$this->table} ({$columnList}, computed_at)
            SELECT fresh.*, CAST(:now AS timestamp) FROM unnest(" . implode(', ', $arguments) . ") AS fresh ({$columnList})
            ON CONFLICT ({$conflictKey}) DO UPDATE SET {$updates}",
            $parameters,
        );
    }

    /**
     * @param list<string> $keys
     */
    private function delete(array $keys): int
    {
        $arguments = [];
        $parameters = [];
        $columns = array_keys($this->keyColumns);
        $parts = array_map(static fn (string $key): array => explode("\x1F", $key), $keys);

        foreach ($columns as $position => $column) {
            $arguments[] = "CAST(:{$column} AS {$this->keyColumns[$column]}[])";
            $parameters[$column] = self::arrayLiteral(
                array_map(static fn (array $part): string => $part[$position], $parts),
                $this->keyColumns[$column] === 'boolean' ? 'varchar' : $this->keyColumns[$column],
            );
        }

        $columnList = implode(', ', $columns);

        return (int) $this->connection->executeStatement(
            "DELETE FROM {$this->table} WHERE ({$columnList}) IN (SELECT * FROM unnest(" . implode(', ', $arguments) . '))',
            $parameters,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function key(array $row): string
    {
        $parts = [];

        foreach ($this->keyColumns as $column => $type) {
            $value = $row[$column] ?? null;

            if (is_string($value)) {
                $value = self::normalized($value, $type);
            }

            $parts[] = match (true) {
                $value === true => 't',
                $value === false => 'f',
                is_scalar($value) => (string) $value,
                default => '',
            };
        }

        return implode("\x1F", $parts);
    }

    /**
     * Numbers and booleans come back from Postgres as PHP numbers and booleans already: only text needs a look.
     *
     * @param array<string, mixed> $row
     * @return list<mixed>
     */
    private function values(array $row): array
    {
        $values = [];

        foreach ($this->valueColumns as $column => $type) {
            $value = $row[$column] ?? null;

            if (is_int($value) && $type === 'double precision') {
                $value = (float) $value;
            } elseif ($value !== null && !is_int($value) && !is_float($value) && !is_bool($value)) {
                $value = self::normalized($value, $type);
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * The same PHP value for a fresh value and the stored one read back: doubles compare exactly (Postgres prints the
     * shortest text that reads back as the same double), timestamps to the second, jsonb decoded.
     */
    private static function normalized(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer', 'smallint' => is_numeric($value) ? (int) $value : throw new \InvalidArgumentException("Not a number: {$type}"),
            'double precision' => is_numeric($value) ? (float) $value : throw new \InvalidArgumentException("Not a number: {$type}"),
            'boolean' => $value === true || $value === 1 || $value === '1' || $value === 't',
            'timestamp' => is_string($value) ? substr($value, 0, 19) : throw new \InvalidArgumentException("Not a timestamp: {$type}"),
            'jsonb' => is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value,
            'uuid' => is_string($value) ? strtolower($value) : throw new \InvalidArgumentException("Not a uuid: {$type}"),
            default => is_scalar($value) ? (string) $value : throw new \InvalidArgumentException("Not a value: {$type}"),
        };
    }

    /**
     * A Postgres array literal of one column's values
     *
     * @param array<mixed> $values
     */
    private static function arrayLiteral(array $values, string $type): string
    {
        $elements = [];

        foreach ($values as $value) {
            $elements[] = self::element($value, $type);
        }

        return '{' . implode(',', $elements) . '}';
    }

    /**
     * One element of a Postgres array literal. Numbers, booleans and uuids need no quotes; text and JSON are quoted and
     * escaped. Floats are written with 17 significant digits, which read back as exactly the same double.
     */
    private static function element(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($type === 'jsonb') {
            $value = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
        }

        return match (true) {
            is_bool($value) => $value ? 't' : 'f',
            is_int($value) => (string) $value,
            is_float($value) => self::floatText($value),
            is_string($value) && $type === 'uuid' => $value,
            is_string($value) => '"' . addcslashes($value, '"\\') . '"',
            default => throw new \InvalidArgumentException('Unsupported value of type ' . get_debug_type($value) . " for {$type}"),
        };
    }

    private static function floatText(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new \InvalidArgumentException('A batch value must be a finite number');
        }

        return sprintf('%.17G', $value);
    }
}
