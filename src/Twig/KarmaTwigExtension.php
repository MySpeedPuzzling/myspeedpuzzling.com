<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Services\Karma\InfiniteKarma;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `karma_infinite(playerId)`: does this player's karma show as "∞" (InfiniteKarma - the kudos)?
 */
final class KarmaTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private InfiniteKarma $infiniteKarma,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('karma_infinite', $this->infiniteKarma->isInfinite(...)),
        ];
    }
}
