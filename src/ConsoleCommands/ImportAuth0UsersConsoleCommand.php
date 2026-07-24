<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\ImportAuth0User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand('myspeedpuzzling:import-auth0-users')]
final class ImportAuth0UsersConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private LoggerInterface $logger,
        readonly private ManagerRegistry $managerRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('bulk-export', InputArgument::REQUIRED, 'Path to the Auth0 bulk user export (NDJSON: user_id, email, email_verified, name, created_at)');
        $this->addArgument('hash-export', InputArgument::REQUIRED, 'Path to the Auth0 password hash export (NDJSON: _id, passwordHash)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $bulkExportPath */
        $bulkExportPath = $input->getArgument('bulk-export');
        /** @var string $hashExportPath */
        $hashExportPath = $input->getArgument('hash-export');

        foreach ([$bulkExportPath, $hashExportPath] as $path) {
            if (!is_readable($path)) {
                $io->error(sprintf('File "%s" does not exist or is not readable', $path));
                return self::FAILURE;
            }
        }

        $passwordHashes = $this->parseHashExport($hashExportPath);
        $io->info(sprintf('Loaded %d password hashes', count($passwordHashes)));

        $dispatched = 0;
        $withHash = 0;
        $skippedNoEmail = 0;
        $skippedInvalid = 0;
        $failed = 0;

        foreach ($this->parseNdjsonLines($bulkExportPath) as $lineNumber => $row) {
            $userId = self::stringValue($row, 'user_id');

            if ($userId === null) {
                $skippedInvalid++;
                $io->warning(sprintf('Line %d: missing user_id, skipping', $lineNumber));
                continue;
            }

            $email = self::stringValue($row, 'email');

            if ($email === null || $email === '') {
                // Cannot log in natively without an email; resolved manually in reconciliation (plan 0.3)
                $skippedNoEmail++;
                $io->warning(sprintf('Line %d: user %s has no email, skipping', $lineNumber, $userId));
                continue;
            }

            $passwordHash = $passwordHashes[$userId] ?? null;

            if ($passwordHash !== null) {
                $withHash++;
                unset($passwordHashes[$userId]);
            }

            try {
                $this->messageBus->dispatch(
                    new ImportAuth0User(
                        userId: $userId,
                        email: $email,
                        emailVerified: self::boolValue($row, 'email_verified'),
                        name: self::stringValue($row, 'name'),
                        registeredAt: self::dateValue($row, 'created_at'),
                        passwordHash: $passwordHash,
                    ),
                );

                $dispatched++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('Auth0 import: failed to import user', [
                    'user_id' => $userId,
                    'exception' => $e,
                ]);
                $io->warning(sprintf('Line %d: import of %s failed: %s', $lineNumber, $userId, $e->getMessage()));

                // A failed flush closes the EntityManager; without a reset every
                // remaining row of the run would fail with "EntityManager is closed"
                $entityManager = $this->managerRegistry->getManager();

                if ($entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
                    $this->managerRegistry->resetManager();
                }
            }

            if ($dispatched % 500 === 0 && $dispatched > 0) {
                $io->writeln(sprintf('Processed %d users...', $dispatched));
            }
        }

        $io->success(sprintf(
            'Imported %d users (%d with password hash, %d without email, %d invalid lines, %d failed)',
            $dispatched,
            $withHash,
            $skippedNoEmail,
            $skippedInvalid,
            $failed,
        ));

        if (count($passwordHashes) > 0) {
            $io->warning(sprintf(
                'Hash export contains %d users absent from the bulk export (stale hash export?): %s',
                count($passwordHashes),
                implode(', ', array_slice(array_keys($passwordHashes), 0, 10)),
            ));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string> user_id => bcrypt password hash
     */
    private function parseHashExport(string $path): array
    {
        $hashes = [];

        foreach ($this->parseNdjsonLines($path) as $row) {
            $passwordHash = self::stringValue($row, 'passwordHash');

            if ($passwordHash === null) {
                continue;
            }

            $userId = self::hashExportUserId($row);

            if ($userId !== null) {
                $hashes[$userId] = $passwordHash;
            }
        }

        return $hashes;
    }

    /**
     * @return \Generator<int, array<mixed>> line number => decoded row
     */
    private function parseNdjsonLines(string $path): \Generator
    {
        $handle = fopen($path, 'r');
        assert($handle !== false);

        try {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                /** @var mixed $row */
                $row = json_decode($line, associative: true);

                if (is_array($row)) {
                    yield $lineNumber => $row;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The hash export identifies users by raw connection id: `_id` is either a plain
     * string or a Mongo-style {"$oid": "..."} object, and maps to our `auth0|<_id>`.
     * A full `user_id` field is used as-is when present.
     *
     * @param array<mixed> $row
     */
    private static function hashExportUserId(array $row): null|string
    {
        $userId = self::stringValue($row, 'user_id');

        if ($userId !== null) {
            return $userId;
        }

        $id = $row['_id'] ?? null;

        if (is_array($id)) {
            $id = $id['$oid'] ?? null;
        }

        if (!is_string($id) || $id === '') {
            return null;
        }

        return str_contains($id, '|') ? $id : 'auth0|' . $id;
    }

    /**
     * @param array<mixed> $row
     */
    private static function stringValue(array $row, string $key): null|string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<mixed> $row
     */
    private static function boolValue(array $row, string $key): bool
    {
        return ($row[$key] ?? null) === true;
    }

    /**
     * @param array<mixed> $row
     */
    private static function dateValue(array $row, string $key): null|DateTimeImmutable
    {
        $value = self::stringValue($row, $key);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
