<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Unlisted;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetUnlistedPuzzles;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the signed-in player has unlisted puzzles - what decides if the menus show "Unlisted puzzles"
 * (docs/features/data-import/unlisted-puzzles.md "Where it is linked from"). **It takes no player id**: it resolves
 * the signed-in player itself, and the answer is only ever about them.
 *
 * The menu is on every page and nearly nobody has unlisted puzzles, so the answer is remembered in the player's
 * session (the pattern of ViewerResultFields): "has none" for two minutes, "has some" for ten. A player without any
 * pays one small statement per two minutes of use - never one per page - and the pinned page budgets, which count a
 * second request, do not move. The unlisted pages tell what they know for free (remember()), so the item appears and
 * disappears at once where the player acts.
 *
 * Its one downside: the first puzzles kept by an import show up in the menu up to two minutes late (the import's own
 * pages link to the list meanwhile).
 */
final class ViewerUnlistedPuzzles implements ResetInterface
{
    private const string SESSION_KEY = 'unlisted_puzzles.has';
    private const int REMEMBER_NONE_SECONDS = 120;
    private const int REMEMBER_SOME_SECONDS = 600;

    private null|bool $answer = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetUnlistedPuzzles $getUnlistedPuzzles,
        readonly private RequestStack $requestStack,
        readonly private ClockInterface $clock,
    ) {
    }

    /**
     * Called by the menus' templates. A guest has none - and costs nothing.
     */
    public function hasAny(): bool
    {
        if ($this->answer !== null) {
            return $this->answer;
        }

        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

        if ($playerId === null) {
            return $this->answer = false;
        }

        $session = $this->session();
        $now = $this->clock->now()->getTimestamp();
        $memo = $session?->get(self::SESSION_KEY);

        if (
            is_array($memo)
            && ($memo['player'] ?? null) === $playerId
            && is_bool($memo['has'] ?? null)
            && is_int($memo['until'] ?? null)
            && $memo['until'] > $now
            // A clock that went back must not make the memo last
            && $memo['until'] <= $now + self::REMEMBER_SOME_SECONDS
        ) {
            return $this->answer = $memo['has'];
        }

        $has = $this->getUnlistedPuzzles->has($playerId);
        $this->store($session, $playerId, $has, $now);

        return $this->answer = $has;
    }

    /**
     * A page that just read the signed-in player's list says what it saw - no statement, and the menu of that very
     * page is right.
     */
    public function remember(bool $has): void
    {
        $playerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

        if ($playerId === null) {
            return;
        }

        $this->store($this->session(), $playerId, $has, $this->clock->now()->getTimestamp());
        $this->answer = $has;
    }

    public function reset(): void
    {
        $this->answer = null;
    }

    private function store(null|SessionInterface $session, string $playerId, bool $has, int $now): void
    {
        $session?->set(self::SESSION_KEY, [
            'player' => $playerId,
            'has' => $has,
            'until' => $now + ($has ? self::REMEMBER_SOME_SECONDS : self::REMEMBER_NONE_SECONDS),
        ]);
    }

    private function session(): null|SessionInterface
    {
        $request = $this->requestStack->getMainRequest();

        return $request !== null && $request->hasSession() ? $request->getSession() : null;
    }
}
