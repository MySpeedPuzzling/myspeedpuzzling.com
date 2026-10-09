<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use SpeedPuzzling\Web\Message\SendXpRevealEmail;
use SpeedPuzzling\Web\Query\GetPlayersForXpRevealEmail;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Launch-day reveal email fan-out — run ONCE, the same day the flag is removed.
 * Safe to re-run: the per-player idempotency log makes duplicates impossible.
 */
#[AsCommand(
    name: 'myspeedpuzzling:send-xp-reveal-emails',
    description: 'Send the one-time XP launch reveal email to every eligible player (staggered, idempotent).',
)]
final class SendXpRevealEmailsConsoleCommand extends Command
{
    /** Badges-backfill pacing: 2s stagger spreads outbound email smoothly. */
    private const int STAGGER_MS = 2000;

    public function __construct(
        readonly private GetPlayersForXpRevealEmail $getPlayersForXpRevealEmail,
        readonly private MessageBusInterface $commandBus,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->xpFeatureGate->isEmailSendingEnabled() === false) {
            $io->error('The xp-system feature flag is still active — remove the flag and deploy BEFORE sending reveal emails.');

            return self::FAILURE;
        }

        $playerIds = $this->getPlayersForXpRevealEmail->execute();

        if ($playerIds === []) {
            $io->success('Nobody left to notify — every eligible player already got the reveal email.');

            return self::SUCCESS;
        }

        foreach ($playerIds as $index => $playerId) {
            $this->commandBus->dispatch(
                new SendXpRevealEmail($playerId),
                [new DelayStamp($index * self::STAGGER_MS)],
            );
        }

        $count = count($playerIds);
        $minutes = (int) ceil($count * self::STAGGER_MS / 60_000);
        $io->success("Dispatched {$count} reveal email(s), spread over ~{$minutes} minutes.");

        return self::SUCCESS;
    }
}
