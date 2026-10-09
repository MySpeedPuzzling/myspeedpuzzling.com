<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\ReconcileRoundResults;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Safety net for the round each solving time belongs to (see RoundResultsReconciler) - the write paths keep
 * it current, this repairs anything they cannot see, e.g. rounds or round puzzles changed by direct SQL. Every series'
 * picks are re-matched first (SeriesEditionReconciler): a secret round puzzle revealed by time records no event.
 */
#[AsCommand('myspeedpuzzling:reconcile-round-results')]
final class ReconcileRoundResultsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new ReconcileRoundResults());

        /** @var null|array{linked: int, moved: int, released: int, roundsLinked: int, roundsUnlinked: int} $result */
        $result = $envelope->last(HandledStamp::class)?->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf(
            'Round results reconciled: %d linked, %d unlinked. Series picks: %d linked, %d moved, %d back to series level.',
            $result['roundsLinked'] ?? 0,
            $result['roundsUnlinked'] ?? 0,
            $result['linked'] ?? 0,
            $result['moved'] ?? 0,
            $result['released'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
