<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\BackfillRoundPuzzleReveals;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * One-off after the reveal model (docs/features/competitions-management/README.md "Hide Until Round Starts"): marks
 * the round puzzles whose puzzle the round created, and sets the puzzle's site-wide hide dates to the reveal moment.
 * Lists what it does; changes nothing without --write.
 */
#[AsCommand('myspeedpuzzling:backfill-round-puzzle-reveals')]
final class BackfillRoundPuzzleRevealsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private Filesystem $filesystem,
        readonly private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_NONE, 'Apply the changes (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $write = $input->getOption('write') === true;
        $envelope = $this->messageBus->dispatch(new BackfillRoundPuzzleReveals(dryRun: !$write));

        /** @var null|array{changes: list<string>, unmatched: list<string>, obsoleteImages: list<string>} $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();
        $changes = $result['changes'] ?? [];
        $unmatched = $result['unmatched'] ?? [];

        $io = new SymfonyStyle($input, $output);

        // The transaction is committed by now (dispatch returned): the puzzles point at their new images, so the old,
        // guessable ones can go. A failed delete only leaves a stray object behind - logged, nothing else.
        foreach ($result['obsoleteImages'] ?? [] as $obsoleteImage) {
            try {
                $this->filesystem->delete($obsoleteImage);
            } catch (FilesystemException $exception) {
                $this->logger->warning('Backfill of round puzzle reveals: could not delete the old image {path}', [
                    'path' => $obsoleteImage,
                    'exception' => $exception,
                ]);
                $io->warning(sprintf('Could not delete the old image %s - delete it by hand.', $obsoleteImage));
            }
        }

        $io->section('Round puzzles that created their puzzle and reveal later');
        $io->listing($changes === [] ? ['nothing to change'] : $changes);
        $io->section('Other puzzles hidden in the future - not touched, review by hand');
        $io->listing($unmatched === [] ? ['none'] : $unmatched);
        $io->success(sprintf('%d round puzzles %s.', count($changes), $write ? 'changed' : 'would change (dry run, add --write)'));

        return self::SUCCESS;
    }
}
