<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Query\GetSuspiciousTimeQueue;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The number of times waiting in the time verification queue, next to its link in the key menu - rendered only for
 * admins and moderators (REVIEW_SUSPICIOUS_TIMES), one indexed count.
 */
final class TimeVerificationTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private GetSuspiciousTimeQueue $getSuspiciousTimeQueue,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('time_verification_pending_count', $this->getSuspiciousTimeQueue->countPending(...)),
        ];
    }
}
