<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SendPlayerContentDigest;
use SpeedPuzzling\Web\Query\GetPlayersForContentDigest;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\DigestPeriod;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsCommand(
    name: 'myspeedpuzzling:send-content-digest',
    description: 'Dispatch the content digest for all eligible players (v1: weekly only). Cron: Sunday 17:00 UTC.',
)]
final class SendContentDigestConsoleCommand extends Command
{
    /**
     * Release pacing: 250 ms stagger caps the maximum SMTP rate at 4/s while the
     * dedicated consumer's natural throughput stays the real ceiling (README D2).
     */
    private const int STAGGER_MS = 250;

    public function __construct(
        readonly private GetPlayersForContentDigest $getPlayersForContentDigest,
        readonly private MessageBusInterface $commandBus,
        readonly private ClockInterface $clock,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::REQUIRED, 'Digest type: weekly (daily is deferred)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getArgument('type');

        if ($type !== 'weekly') {
            $io->error('Only the weekly digest exists in v1 — the daily digest is deferred.');

            return self::INVALID;
        }

        // No digest leaves the system while the xp-system flag is active (§1.10).
        if ($this->xpFeatureGate->isEmailSendingEnabled() === false) {
            $io->warning('xp-system feature flag is active — digest sending is suppressed, nothing dispatched.');

            return self::SUCCESS;
        }

        $period = DigestPeriod::weeklyFor($this->clock->now());
        $playerIds = $this->getPlayersForContentDigest->weekly($period->key);

        foreach ($playerIds as $index => $playerId) {
            $this->commandBus->dispatch(
                new SendPlayerContentDigest($playerId, 'weekly', $period->key),
                [new DelayStamp($index * self::STAGGER_MS)],
            );
        }

        $count = count($playerIds);
        $io->success("Dispatched {$count} weekly digest message(s) for period {$period->key}.");

        return self::SUCCESS;
    }
}
