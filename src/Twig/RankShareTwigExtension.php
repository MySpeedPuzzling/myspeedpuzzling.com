<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Value\RankShare;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ include('_rank_share.html.twig', {share: rank_share(rank, total)}) }}` - the share next to a rank on a puzzle
 */
final class RankShareTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('rank_share', RankShare::of(...)),
        ];
    }
}
