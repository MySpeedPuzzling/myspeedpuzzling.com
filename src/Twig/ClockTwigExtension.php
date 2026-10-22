<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `now()` - the app's clock in templates (docs/features/time-and-clock/README.md R1): Twig's own `date()` and
 * `'now'|date` read the system clock, which a test cannot freeze. Format it as any date: `now()|date('Y')`.
 */
final class ClockTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('now', $this->now(...)),
        ];
    }

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
