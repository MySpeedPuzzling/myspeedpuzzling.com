<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\ConsoleCommands;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\InvalidDigestPeriod;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendContentDigestPreview;
use SpeedPuzzling\Web\Services\ResolvePlayerByIdentifier;
use SpeedPuzzling\Web\Value\ContentDigestKind;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * See a player's real digest e-mail in your own inbox before the run (docs/features/content-digest/README.md
 * "Sending") - their numbers, the real template, headers and transport; writes nothing.
 */
#[AsCommand(
    name: 'myspeedpuzzling:send-content-digest-preview',
    description: 'Send one player\'s activity digest (or "We miss you" e-mail) for a period to one address',
)]
final class SendContentDigestPreviewConsoleCommand extends Command
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private ResolvePlayerByIdentifier $resolvePlayerByIdentifier,
        readonly private ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Where to send the preview')
            ->addOption('player', null, InputOption::VALUE_REQUIRED, 'Whose digest: player id, code or account e-mail (default: the player of <email>)')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Period key, e.g. 2026-W42, 2026-W42-W43, 2026-10 (default: last week)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'activity or miss_you', ContentDigestKind::Activity->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $email */
        $email = $input->getArgument('email');
        /** @var null|string $playerIdentifier */
        $playerIdentifier = $input->getOption('player');
        /** @var null|string $periodKey */
        $periodKey = $input->getOption('period');
        /** @var string $kindName */
        $kindName = $input->getOption('kind');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $io->error(sprintf('"%s" is not an e-mail address.', $email));

            return self::FAILURE;
        }

        $kind = ContentDigestKind::tryFrom($kindName);

        if ($kind === null) {
            $io->error(sprintf('Unknown kind "%s" - use activity or miss_you.', $kindName));

            return self::FAILURE;
        }

        try {
            $player = $this->resolvePlayerByIdentifier->resolve($playerIdentifier ?? $email);
        } catch (PlayerNotFound) {
            $io->error(sprintf('No player found for "%s".', $playerIdentifier ?? $email));

            return self::FAILURE;
        }

        try {
            $period = $periodKey === null
                ? DigestPeriod::containing(DigestPeriodLength::Week, $this->clock->now())->previous()
                : DigestPeriod::fromKey($periodKey);
        } catch (InvalidDigestPeriod) {
            $io->error(sprintf('"%s" is not a period key.', $periodKey));

            return self::FAILURE;
        }

        $this->messageBus->dispatch(new SendContentDigestPreview($player->id->toString(), $email, $kind, $period->key));

        $io->success(sprintf('Digest of #%s for %s (%s) sent to %s.', $player->code, $period->key, $kind->value, $email));

        return self::SUCCESS;
    }
}
