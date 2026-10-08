<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A player's own list page other than a collection, where members select several puzzles at once
 * (docs/features/collections/bulk-actions.md "Other lists"). The value is the list's URL segment.
 */
enum PuzzleList: string
{
    case Wishlist = 'wishlist';
    case SellSwap = 'sell-swap';
    case Unsolved = 'unsolved';
    case LendBorrow = 'lend-borrow';

    public const string ROUTE_REQUIREMENT = 'wishlist|sell-swap|unsolved|lend-borrow';

    public function pageRoute(): string
    {
        return match ($this) {
            self::Wishlist => 'wish_list_detail',
            self::SellSwap => 'sell_swap_list_detail',
            self::Unsolved => 'unsolved_puzzles_detail',
            self::LendBorrow => 'lend_borrow_list_detail',
        };
    }

    /**
     * The card id prefix and the page's count / list container ids, for the Turbo Streams taking cards off the page -
     * null where a change reloads the page instead
     *
     * @return null|array{page_context: string, count_target: string, container_target: string, empty_state: string}
     */
    public function streamTargets(): null|array
    {
        return match ($this) {
            self::Wishlist => [
                'page_context' => 'wishlist',
                'count_target' => 'wishlist-count',
                'container_target' => 'wishlist-list-container',
                'empty_state' => 'wishlist/_empty_state.html.twig',
            ],
            self::SellSwap => [
                'page_context' => 'sell-swap',
                'count_target' => 'sellswap-count',
                'container_target' => 'sellswap-list-container',
                'empty_state' => 'sell-swap/_empty_state.html.twig',
            ],
            self::Unsolved, self::LendBorrow => null,
        };
    }
}
