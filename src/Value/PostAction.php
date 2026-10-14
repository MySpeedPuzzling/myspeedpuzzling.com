<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Controller\AddComparisonSubjectController;
use SpeedPuzzling\Web\Controller\Events\AbstractEventFollowController;
use SpeedPuzzling\Web\Controller\Messaging\BlockUserController;
use SpeedPuzzling\Web\Controller\RemoveComparisonSubjectController;
use SpeedPuzzling\Web\Controller\SetMyCountryController;

/**
 * The one-tap actions of the browse pages (docs/features/navigation README §4.13 G9, requirement GEST-15, decision 99).
 *
 * A browse page that opts in to Turbo's restore cache carries no POST form (TurboCacheControlOptInTest): a page restored
 * by Back may be up to 10 minutes old. Its one-tap actions are links instead - post_action_controller.js POSTs to the
 * action's route with fetch (one tap, as before: a Turbo Stream applied, or the redirect followed) and clears Turbo's
 * cache (the app's web view runs it too); without JavaScript or in a new tab the link opens `post_action_confirm`, a page
 * with a single button posting the same form. The action that asks before it acts (Block - it asked in a modal before
 * too) opens that view in the modal frame - the view itself is the confirmation, and its form lives in the frame, never
 * in the cached page.
 *
 * The allow-list: only these actions, each with its route, the values it passes on (and their shapes, PostActionParameter)
 * and its CSRF token id.
 */
enum PostAction: string
{
    case CompareAdd = 'compare_add';
    case CompareRemove = 'compare_remove';
    case Follow = 'follow';
    case Unfollow = 'unfollow';
    case SetCountry = 'set_country';
    case BlockPlayer = 'block_player';
    case PublishCompetition = 'publish_competition';
    case PublishSeries = 'publish_series';
    case PublishOrganization = 'publish_organization';
    case WishlistRemove = 'wishlist_remove';
    case CollectionRemove = 'collection_remove';
    case ReservationRemove = 'reservation_remove';
    case SellSwapRemove = 'sell_swap_remove';
    case PuzzleReturn = 'puzzle_return';

    /** The route the action posts to */
    public function route(): string
    {
        return match ($this) {
            self::CompareAdd => 'comparison_add',
            self::CompareRemove => 'comparison_remove',
            self::Follow => 'event_follow',
            self::Unfollow => 'event_unfollow',
            self::SetCountry => 'set_my_country',
            self::BlockPlayer => 'block_user',
            self::PublishCompetition => 'publish_competition',
            self::PublishSeries => 'publish_competition_series',
            self::PublishOrganization => 'publish_organization',
            self::WishlistRemove => 'remove_puzzle_from_wish_list',
            self::CollectionRemove => 'collection_remove',
            self::ReservationRemove => 'sell_swap_remove_reservation',
            self::SellSwapRemove => 'sellswap_remove',
            self::PuzzleReturn => 'return_puzzle',
        };
    }

    /**
     * The route's own parameters (all required)
     *
     * @return array<string, PostActionParameter>
     */
    public function routeParameters(): array
    {
        return match ($this) {
            self::CompareAdd, self::CompareRemove, self::Follow, self::Unfollow, self::SetCountry => [],
            self::BlockPlayer => ['playerId' => PostActionParameter::Uuid],
            self::PublishCompetition => ['competitionId' => PostActionParameter::Uuid],
            self::PublishSeries => ['seriesId' => PostActionParameter::Uuid],
            self::PublishOrganization => ['organizationId' => PostActionParameter::Uuid],
            self::WishlistRemove, self::CollectionRemove, self::SellSwapRemove => ['puzzleId' => PostActionParameter::Uuid],
            self::ReservationRemove => ['itemId' => PostActionParameter::Uuid],
            self::PuzzleReturn => ['lentPuzzleId' => PostActionParameter::Uuid],
        };
    }

    /**
     * The form fields the action posts (each optional; anything else is never passed on)
     *
     * @return array<string, PostActionParameter>
     */
    public function fields(): array
    {
        return match ($this) {
            self::CompareAdd, self::CompareRemove => ['subject' => PostActionParameter::Subject, 'return' => PostActionParameter::ReturnPath],
            self::Follow, self::Unfollow => ['target' => PostActionParameter::FollowTarget, 'return' => PostActionParameter::ReturnPath],
            self::SetCountry => ['country' => PostActionParameter::Country],
            self::BlockPlayer => [],
            self::PublishCompetition, self::PublishSeries, self::PublishOrganization => ['return' => PostActionParameter::ReturnPath],
            self::WishlistRemove => ['context' => PostActionParameter::Context],
            self::CollectionRemove => [
                'collectionId' => PostActionParameter::Collection,
                'currentCollectionId' => PostActionParameter::Collection,
                'context' => PostActionParameter::Context,
            ],
            self::ReservationRemove, self::SellSwapRemove => ['context' => PostActionParameter::Context, 'collection_id' => PostActionParameter::Collection],
            self::PuzzleReturn => [
                'context' => PostActionParameter::Context,
                'tab' => PostActionParameter::Tab,
                'collection_id' => PostActionParameter::Collection,
            ],
        };
    }

    /**
     * The CSRF token id the route checks (null: it checks none)
     *
     * @param array<string, string> $routeParameters
     */
    public function csrfTokenId(array $routeParameters): null|string
    {
        return match ($this) {
            self::CompareAdd => AddComparisonSubjectController::CSRF_TOKEN_ID,
            self::CompareRemove => RemoveComparisonSubjectController::CSRF_TOKEN_ID,
            self::Follow, self::Unfollow => AbstractEventFollowController::CSRF_TOKEN_ID,
            self::SetCountry => SetMyCountryController::CSRF_TOKEN_ID,
            self::BlockPlayer => BlockUserController::CSRF_TOKEN_ID,
            self::PublishCompetition => 'publish_competition_' . ($routeParameters['competitionId'] ?? ''),
            self::PublishSeries => 'publish_competition_series_' . ($routeParameters['seriesId'] ?? ''),
            self::PublishOrganization => 'publish_organization_' . ($routeParameters['organizationId'] ?? ''),
            self::WishlistRemove, self::CollectionRemove, self::ReservationRemove, self::SellSwapRemove, self::PuzzleReturn => null,
        };
    }

    /** What the action does - the confirmation view's heading and its button */
    public function labelKey(): string
    {
        return match ($this) {
            self::CompareAdd => 'comparison_entry.add',
            self::CompareRemove => 'comparison_entry.remove',
            self::Follow => 'events_organizer.follow.title_follow',
            self::Unfollow => 'post_action.unfollow',
            self::SetCountry => 'players.nudge.save',
            self::BlockPlayer => 'blocklist.block_player',
            self::PublishCompetition, self::PublishOrganization => 'drafts.banner.publish',
            self::PublishSeries => 'drafts.banner.publish_series',
            self::WishlistRemove => 'wish_list.remove.button',
            self::CollectionRemove => 'post_action.collection_remove',
            self::ReservationRemove => 'sell_swap_list.remove_reservation.button',
            self::SellSwapRemove => 'sell_swap_list.remove.button',
            self::PuzzleReturn => 'lend_borrow.return.button',
        };
    }

    /** The confirmation view's heading (the Block dialog keeps its question) */
    public function titleKey(): string
    {
        return match ($this) {
            self::BlockPlayer => 'blocklist.modal_title',
            self::SetCountry => 'players.nudge.page_title',
            default => $this->labelKey(),
        };
    }

    /**
     * The view's own lines under the heading (the Block dialog keeps its explanation)
     *
     * @return list<string>
     */
    public function textKeys(): array
    {
        return match ($this) {
            self::BlockPlayer => ['blocklist.modal_text', 'blocklist.modal_undo'],
            default => ['post_action.lead'],
        };
    }

    /**
     * Asks before it acts: the link opens the confirmation view in the modal frame (no one-tap fetch) - Block, which
     * asked in a modal before too. Publish was one tap (the draft banner's button) and stays one.
     */
    public function confirmsInFrame(): bool
    {
        return $this === self::BlockPlayer;
    }

    /** Removes or blocks something: the button is red */
    public function isDanger(): bool
    {
        return match ($this) {
            self::BlockPlayer, self::WishlistRemove, self::CollectionRemove, self::SellSwapRemove => true,
            default => false,
        };
    }

    /**
     * The route parameters from user input (the confirmation view's query): every one present and of its shape, else null
     *
     * @param array<array-key, mixed> $input
     * @return null|array<string, string>
     */
    public function routeParametersFrom(array $input): null|array
    {
        $parameters = [];

        foreach ($this->routeParameters() as $name => $kind) {
            $value = $input[$name] ?? null;

            if (!is_string($value) || $kind->accepts($value) === false) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return $parameters;
    }

    /**
     * The fields from user input: those present, each of its shape (a field of another shape: null - the input is refused)
     *
     * @param array<array-key, mixed> $input
     * @return null|array<string, string>
     */
    public function fieldsFrom(array $input): null|array
    {
        $fields = [];

        foreach ($this->fields() as $name => $kind) {
            if (!array_key_exists($name, $input)) {
                continue;
            }

            $value = $input[$name];

            if (!is_string($value) || $kind->accepts($value) === false) {
                return null;
            }

            $fields[$name] = $value;
        }

        return $fields;
    }
}
