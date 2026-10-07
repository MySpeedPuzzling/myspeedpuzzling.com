<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\RoundBadgeColor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `round_badge(chosenColor, schedulePosition)` - how a round's badge looks on the event pages (RoundBadgeColor), for
 * the round form's preview: `background`, `text`, `automatic` (no colour of its own) and `automaticBackground`
 * (the colour it gets without one).
 */
final class RoundBadgeTwigExtension extends AbstractExtension
{
    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('round_badge', $this->roundBadge(...)),
        ];
    }

    /**
     * @return array{background: string, text: string, automatic: bool, automaticBackground: string}
     */
    public function roundBadge(null|string $chosenColor, int $schedulePosition): array
    {
        $background = RoundBadgeColor::background($chosenColor, $schedulePosition);

        return [
            'background' => $background,
            'text' => RoundBadgeColor::text($background),
            'automatic' => RoundBadgeColor::chosen($chosenColor) === null,
            'automaticBackground' => RoundBadgeColor::automatic($schedulePosition),
        ];
    }
}
