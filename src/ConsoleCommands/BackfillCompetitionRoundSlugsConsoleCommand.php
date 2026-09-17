<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\BackfillCompetitionRoundSlugs;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand('myspeedpuzzling:backfill-round-slugs')]
final class BackfillCompetitionRoundSlugsConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $envelope = $this->messageBus->dispatch(new BackfillCompetitionRoundSlugs());

        /** @var null|int $assigned */
        $assigned = $envelope->last(HandledStamp::class)?->getResult();

        (new SymfonyStyle($input, $output))->success(sprintf('%d rounds got a slug.', $assigned ?? 0));

        return self::SUCCESS;
    }
}
