<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use Symfony\Component\HttpFoundation\Request;

/**
 * The signed-in player's fields for results, for the pages nearly everybody opens without having any (the add form,
 * the result detail): one statement, and "has none" is remembered in the player's session for ten minutes - so a
 * player without custom fields pays that statement once per ten minutes, not on every page
 * (docs/features/custom-fields.md "What it costs"). A player with fields pays it every time.
 *
 * The other side of the memo: a first field added on another device shows up here within ten minutes; on this
 * device at once - the manage page calls fieldsChanged().
 */
readonly final class ViewerResultFields
{
    private const string SESSION_KEY = 'my_fields.no_result_fields';
    private const int REMEMBER_NONE_SECONDS = 600;

    public function __construct(
        private GetCustomFields $getCustomFields,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<CustomFieldOverview> for results, not archived, in the player's order
     */
    public function of(Request $request, string $playerId): array
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $now = $this->clock->now()->getTimestamp();
        $memo = $session?->get(self::SESSION_KEY);

        if (
            is_array($memo)
            && ($memo['player'] ?? null) === $playerId
            && is_int($memo['until'] ?? null)
            && $memo['until'] > $now
            // A clock that went back must not make the memo last
            && $memo['until'] <= $now + self::REMEMBER_NONE_SECONDS
        ) {
            return [];
        }

        $fields = $this->getCustomFields->usableOnResults($playerId);

        if ($fields === []) {
            $session?->set(self::SESSION_KEY, ['player' => $playerId, 'until' => $now + self::REMEMBER_NONE_SECONDS]);
        } elseif ($memo !== null) {
            $session->remove(self::SESSION_KEY);
        }

        return $fields;
    }

    public function fieldsChanged(Request $request): void
    {
        if ($request->hasSession()) {
            $request->getSession()->remove(self::SESSION_KEY);
        }
    }
}
