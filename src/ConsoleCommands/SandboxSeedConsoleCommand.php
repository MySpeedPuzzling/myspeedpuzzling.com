<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\CreateSandboxPlayers;
use SpeedPuzzling\Web\Query\GetSandboxSimulationData;
use SpeedPuzzling\Web\Services\Sandbox\SandboxMode;
use SpeedPuzzling\Web\Value\SandboxPlannedResult;
use SpeedPuzzling\Web\Value\SandboxPlayer;
use SpeedPuzzling\Web\Services\Sandbox\SandboxResultPlanner;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Fills the sandbox with made-up people and their history (docs/features/sandbox/README.md "Data"). Sandbox only
 * (SANDBOX=1) - it refuses anywhere else before writing anything. Run it after the catalogue import.
 *
 * Every result goes through AddPuzzleSolvingTime - the add-time form's message - with the app's clock set to the
 * moment the made-up player saves it (Clock::set(), the `clock` service follows the global clock), so tracked dates,
 * XP, first tries and feeds look like a history that happened, not like 25,000 results saved today.
 *
 * Safe to run again: players that exist are kept, and only players without any result get a history.
 */
#[AsCommand(
    name: 'myspeedpuzzling:sandbox:seed',
    description: 'Create the sandbox\'s made-up players and log their history of results (SANDBOX=1 only).',
)]
final class SandboxSeedConsoleCommand extends Command
{
    public function __construct(
        readonly private SandboxMode $sandboxMode,
        readonly private MessageBusInterface $messageBus,
        readonly private GetSandboxSimulationData $simulationData,
        readonly private SandboxResultPlanner $planner,
        readonly private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('players', null, InputOption::VALUE_REQUIRED, 'How many made-up players (test-user+1..N@myspeedpuzzling.com)', '200')
            ->addOption('results', null, InputOption::VALUE_REQUIRED, 'Results for all of them together, over the last three years', '25000')
            ->addOption('pool', null, InputOption::VALUE_REQUIRED, 'How many catalogue puzzles they solve', '3000')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'The one password of every made-up player (published - it is a sandbox)', 'sandbox')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Random seed - the same seed plans the same history', '2026');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->sandboxMode->assertOn();

        $password = $input->getOption('password');
        assert(is_string($password));

        $envelope = $this->messageBus->dispatch(new CreateSandboxPlayers(self::intOption($input, 'players'), $password));
        $created = $envelope->last(HandledStamp::class)?->getResult();
        $io->writeln(sprintf('Players created: %d', is_int($created) ? $created : 0));

        $puzzles = $this->simulationData->puzzlePool(self::intOption($input, 'pool'));

        if ($puzzles === []) {
            $io->error('No catalogue puzzles - run myspeedpuzzling:sandbox:import-catalogue first.');

            return self::FAILURE;
        }

        $players = $this->simulationData->players();
        $withoutHistory = array_values(array_filter(
            $players,
            fn (SandboxPlayer $player): bool => $this->simulationData->solvedCounts($player->id) === [],
        ));

        if ($withoutHistory === []) {
            $io->success('Every made-up player has a history already - nothing to do.');

            return self::SUCCESS;
        }

        $results = (int) round(self::intOption($input, 'results') * count($withoutHistory) / count($players));
        $plan = $this->planner->history($withoutHistory, $puzzles, $results, self::intOption($input, 'seed'));
        $io->writeln(sprintf('Logging %d results of %d players (the consumer handles what they queue - let it run)...', count($plan), count($withoutHistory)));

        $progress = new ProgressBar($output, count($plan));
        $progress->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progress->start();
        $failed = [];
        // @phpstan-ignore clock.systemTime (the global clock this command moves through the history and puts back)
        $realClock = Clock::get();

        try {
            foreach ($plan as $index => $result) {
                Clock::set(new MockClock($result->trackedAt));

                try {
                    $this->messageBus->dispatch(self::message($result));
                } catch (\Throwable $e) {
                    $failed[] = sprintf('%s: %s', $result->timeId->toString(), $e->getMessage());
                }

                // 25k handler runs in one process: keep the unit of work small
                if ($index % 50 === 49) {
                    $this->entityManager->clear();
                }

                $progress->advance();
            }
        } finally {
            Clock::set($realClock);
        }

        $progress->finish();
        $io->newLine(2);

        if ($failed !== []) {
            $io->warning(sprintf('%d results were refused - the first ones:', count($failed)));
            $io->listing(array_slice($failed, 0, 10));
        }

        $io->success('Seeded. Next: the follow-up commands in docs/features/sandbox/README.md "First seed".');

        return self::SUCCESS;
    }

    public static function intOption(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);

        if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('--%s must be a whole number.', $name));
        }

        return (int) $value;
    }

    public static function message(SandboxPlannedResult $result): AddPuzzleSolvingTime
    {
        return new AddPuzzleSolvingTime(
            timeId: $result->timeId,
            userId: $result->player->userId,
            puzzleId: $result->puzzle->id,
            competitionId: null,
            time: $result->timeInput(),
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $result->groupPlayers,
            finishedAt: $result->finishedAt,
            firstAttempt: $result->firstAttempt,
            unboxed: $result->unboxed,
            createdVia: SolvingTimeSource::Form,
        );
    }
}
