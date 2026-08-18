<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\SyncWjpfIdentity;
use SpeedPuzzling\Web\Query\GetPlayersForWjpfSync;
use SpeedPuzzling\Web\Results\WjpfSyncCandidate;
use SpeedPuzzling\Web\Services\Wjpf\WjpfClient;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Throwable;

/**
 * Matches MySpeedPuzzling players against the WJPF player database by e-mail.
 *
 * Runs read-only by default. `--claim` additionally sends our player id so their side stores
 * it - that write is permanent and cannot be undone or corrected from here, so survey first.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sync-wjpf-identities',
    description: 'Look up players in the WJPF database and store the mapping (read-only unless --claim)',
)]
final class SyncWjpfIdentitiesConsoleCommand extends Command
{
    /** Their host is a small shared box - a burst of requests reads as an attack. */
    private const int DEFAULT_DELAY_MS = 1000;

    /** A long run must not keep hammering a host that has stopped answering. */
    private const int MAX_CONSECUTIVE_FAILURES = 10;

    /**
     * Every dispatch leaves a Player and a WjpfIdentity in Doctrine's identity map, plus a
     * second copy in the UnitOfWork for change detection and the stored response payload.
     * Over ~10k players that is enough to exhaust the process: the first full backfill
     * (2026-08-09) reached 100% and then died on an OOM instead of printing its summary.
     */
    private const int CLEAR_ENTITY_MANAGER_EVERY = 100;

    public function __construct(
        readonly private MessageBusInterface $commandBus,
        readonly private GetPlayersForWjpfSync $getPlayersForWjpfSync,
        readonly private WjpfClient $wjpfClient,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most this many players')
            ->addOption('claim', null, InputOption::VALUE_NONE, 'Send our player id so WJPF stores it (permanent on their side)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-check players already checked but not yet paired (e.g. people who have joined WJPF since)')
            ->addOption('include-paired', null, InputOption::VALUE_NONE, 'With --force, also re-check players we already hold a WJPF id for')
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Check a single player id, ignoring all filters')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait between requests', (string) self::DEFAULT_DELAY_MS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->wjpfClient->isEnabled() === false) {
            $io->error('WJPF integration is not configured - set WJPF_API_URL and WJPF_API_TOKEN.');

            return self::FAILURE;
        }

        $claim = $input->getOption('claim') === true;
        $delayOption = $input->getOption('delay');
        $delayMs = is_string($delayOption) || is_int($delayOption) ? max(0, (int) $delayOption) : self::DEFAULT_DELAY_MS;
        $candidates = $this->resolveCandidates($input);

        if ($candidates === []) {
            $io->success('No players to check.');

            return self::SUCCESS;
        }

        $io->title(sprintf('WJPF sync - %d player(s), %s', count($candidates), $claim ? 'CLAIMING' : 'read-only survey'));

        if ($claim) {
            $io->warning('--claim writes our player id into the WJPF database. Their side stores it only once and it cannot be changed afterwards.');
        }

        $counts = [
            WjpfPairingStatus::Paired->value => 0,
            WjpfPairingStatus::Conflict->value => 0,
            WjpfPairingStatus::NotFound->value => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $consecutiveFailures = 0;
        $aborted = false;

        $progressBar = $io->createProgressBar(count($candidates));
        $progressBar->start();

        foreach ($candidates as $index => $candidate) {
            if ($index > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $status = $this->dispatch($candidate->playerId, $claim);
                $consecutiveFailures = 0;

                if ($status === null) {
                    ++$counts['skipped'];
                } else {
                    ++$counts[$status->value];
                }

                if ($status === WjpfPairingStatus::Conflict) {
                    $io->writeln('');
                    $io->warning(sprintf(
                        'Conflict for %s <%s> - their record points at a different player, see the log.',
                        $candidate->playerName ?? $candidate->playerId,
                        $candidate->email,
                    ));
                }
            } catch (Throwable $e) {
                ++$counts['failed'];
                ++$consecutiveFailures;

                $io->writeln('');
                $io->warning(sprintf('%s <%s>: %s', $candidate->playerName ?? $candidate->playerId, $candidate->email, $this->unwrap($e)->getMessage()));

                if ($consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                    $aborted = true;

                    break;
                }
            } finally {
                $progressBar->advance();

                // Safe here and nowhere else: the doctrine_transaction middleware has already
                // committed this player's work, and the loop holds only plain DTOs.
                if (($index + 1) % self::CLEAR_ENTITY_MANAGER_EVERY === 0) {
                    $this->entityManager->clear();
                }
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->table(
            ['Outcome', 'Players'],
            [
                ['Paired', $counts[WjpfPairingStatus::Paired->value]],
                ['Conflict', $counts[WjpfPairingStatus::Conflict->value]],
                ['Not found at WJPF', $counts[WjpfPairingStatus::NotFound->value]],
                ['Skipped', $counts['skipped']],
                ['Failed', $counts['failed']],
                // Printed so a long run leaves evidence about memory rather than only
                // proving it was a problem by dying of it.
                ['Peak memory', sprintf('%d MB', (int) round(memory_get_peak_usage(true) / 1048576))],
            ],
        );

        if ($aborted) {
            $io->error(sprintf('Aborted after %d consecutive failures - WJPF looks unreachable.', self::MAX_CONSECUTIVE_FAILURES));

            return self::FAILURE;
        }

        if ($claim === false && $counts[WjpfPairingStatus::Paired->value] > 0) {
            $io->note('This was a read-only survey - nothing was written on the WJPF side. Re-run with --claim to pair for real.');
        }

        $io->success('Done.');

        return self::SUCCESS;
    }

    /**
     * @return list<WjpfSyncCandidate>
     */
    private function resolveCandidates(InputInterface $input): array
    {
        $singlePlayerId = $input->getOption('player');

        if (is_string($singlePlayerId) && $singlePlayerId !== '') {
            return [new WjpfSyncCandidate($singlePlayerId, '(resolved by handler)', null)];
        }

        $limitOption = $input->getOption('limit');
        $limit = is_string($limitOption) && $limitOption !== '' ? max(1, (int) $limitOption) : null;

        return $this->getPlayersForWjpfSync->all(
            limit: $limit,
            includeAlreadyChecked: $input->getOption('force') === true,
            includePaired: $input->getOption('include-paired') === true,
        );
    }

    private function dispatch(string $playerId, bool $claim): null|WjpfPairingStatus
    {
        $envelope = $this->commandBus->dispatch(new SyncWjpfIdentity($playerId, $claim));
        $handled = $envelope->last(HandledStamp::class);
        $result = $handled?->getResult();

        return $result instanceof WjpfPairingStatus ? $result : null;
    }

    private function unwrap(Throwable $e): Throwable
    {
        return $e instanceof HandlerFailedException ? ($e->getPrevious() ?? $e) : $e;
    }
}
