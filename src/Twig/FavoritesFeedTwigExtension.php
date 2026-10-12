<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Twig;

use SpeedPuzzling\Web\Navigation\FavoritesFeed;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * favorites_new_count(): "N new" on the Hub's Favorites tab (docs/features/navigation INB-04b) - counted from the rows
 * the feed below it renders (FavoritesFeed, no statement of its own); null without a last visit.
 */
final class FavoritesFeedTwigExtension extends AbstractExtension
{
    public function __construct(
        readonly private FavoritesFeed $favoritesFeed,
    ) {
    }

    /**
     * @return array<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('favorites_new_count', $this->favoritesFeed->newCount(...)),
        ];
    }
}
