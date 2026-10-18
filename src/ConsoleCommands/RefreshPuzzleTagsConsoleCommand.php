<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\RefreshPuzzleTags;
use SpeedPuzzling\Web\Results\PuzzleTagWriteOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Writes puzzle_tag from the `tags` analyses and the corrections (docs/features/insights/tags.md) - daily, after the
 * image pipeline's derive step. Only puzzles whose input changed since their last write; --full rewrites every one.
 */
#[AsCommand(
    name: 'myspeedpuzzling:refresh-puzzle-tags',
    description: 'Write the puzzles\' picture tags (puzzle_tag) from the tags analyses and the corrections.',
)]
final class RefreshPuzzleTagsConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('full', null, InputOption::VALUE_NONE, 'Rewrite every puzzle, not only those whose input changed (after a change of the rules).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $envelope = $this->messageBus->dispatch(new RefreshPuzzleTags(full: $input->getOption('full') === true));

        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof PuzzleTagWriteOutcome);

        (new SymfonyStyle($input, $output))->success(sprintf(
            '%d puzzles rewritten: %d tags written, %d removed (%d ms).',
            $outcome->puzzles,
            $outcome->written,
            $outcome->removed,
            (int) round((microtime(true) - $startedAt) * 1000),
        ));

        return self::SUCCESS;
    }
}
