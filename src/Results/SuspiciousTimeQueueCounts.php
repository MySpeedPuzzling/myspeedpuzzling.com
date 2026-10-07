<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SuspiciousTimeQueueTab;

/**
 * The numbers on the tabs of the time verification queue.
 */
readonly final class SuspiciousTimeQueueCounts
{
    public function __construct(
        public int $fast,
        public int $slow,
        public int $replied,
        public int $marked,
        public int $trusted,
        public int $decisions,
    ) {
    }

    public function of(SuspiciousTimeQueueTab $tab): null|int
    {
        return match ($tab) {
            SuspiciousTimeQueueTab::Fast => $this->fast,
            SuspiciousTimeQueueTab::Slow => $this->slow,
            SuspiciousTimeQueueTab::Replied => $this->replied,
            SuspiciousTimeQueueTab::Marked => $this->marked,
            SuspiciousTimeQueueTab::Trusted => $this->trusted,
            SuspiciousTimeQueueTab::Log => $this->decisions,
            SuspiciousTimeQueueTab::Numbers => null,
        };
    }
}
