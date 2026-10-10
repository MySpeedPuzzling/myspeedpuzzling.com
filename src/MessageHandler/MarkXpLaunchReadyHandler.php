<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\XpLaunchNotVerified;
use SpeedPuzzling\Web\Message\MarkXpLaunchReady;
use SpeedPuzzling\Web\Query\GetXpLaunchVerification;
use SpeedPuzzling\Web\Repository\XpSettingsRepository;
use SpeedPuzzling\Web\Results\XpLaunchVerification;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sets `xp_settings.launch_ready_at` - the moment XP, levels and achievements appear for everybody - and only when
 * the history verifies: nobody may meet "Level 1, 1 XP, no achievements" because the backfill was not finished.
 * The caller drops the cached answer afterwards (XpLaunchReadiness::forget()), once this transaction committed.
 */
#[AsMessageHandler]
readonly final class MarkXpLaunchReadyHandler
{
    public function __construct(
        private GetXpLaunchVerification $getXpLaunchVerification,
        private XpSettingsRepository $xpSettingsRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws XpLaunchNotVerified
     */
    public function __invoke(MarkXpLaunchReady $message): XpLaunchVerification
    {
        $verification = $this->getXpLaunchVerification->check();

        if ($verification->passed() === false) {
            throw new XpLaunchNotVerified($verification);
        }

        $this->xpSettingsRepository->get()->markLaunchReady($this->clock->now());

        return $verification;
    }
}
