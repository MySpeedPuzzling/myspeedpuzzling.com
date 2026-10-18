<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\DetectBoxTextMismatches;
use SpeedPuzzling\Web\Results\BoxTextDetectionOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Rewrites the moderators' box-text mismatch list (docs/features/puzzle-approvals.md, "Box-text mismatches") - daily,
 * after the image pipeline's derive step.
 */
#[AsCommand(
    name: 'myspeedpuzzling:detect-box-text-mismatches',
    description: 'Compare every analysed box (box_text) with its puzzle\'s record and rewrite the moderators\' mismatch list.',
)]
final class DetectBoxTextMismatchesConsoleCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $startedAt = microtime(true);
        $envelope = $this->messageBus->dispatch(new DetectBoxTextMismatches());

        $outcome = $envelope->last(HandledStamp::class)?->getResult();
        assert($outcome instanceof BoxTextDetectionOutcome);

        (new SymfonyStyle($input, $output))->success(sprintf(
            '%d boxes checked, %d mismatches (%d rows written, %d removed) in %d ms.',
            $outcome->puzzlesChecked,
            $outcome->mismatches,
            $outcome->written,
            $outcome->removed,
            (int) round((microtime(true) - $startedAt) * 1000),
        ));

        return self::SUCCESS;
    }
}
