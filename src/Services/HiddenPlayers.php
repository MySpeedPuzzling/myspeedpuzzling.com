<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Closure;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The players the current viewer must never be shown - see docs/features/player-blocklist.md.
 *
 * Read-side queries embed {@see self::sqlExclude()} / {@see self::sqlExcludeTeam()} in their SQL.
 * Nearly every viewer has nobody hidden, and then both return an empty string: the query text and
 * its plan are exactly what they were before the blocklist existed. On the web the set costs no
 * query of its own: it rides along on the signed-in player's profile, which every page loads
 * anyway. API requests pay one index-only lookup.
 *
 * No signed-in viewer (guests, cron, async consumers) means nothing is hidden - guest pages are
 * shared-cached and must never depend on anyone's blocks. The admin area sees everyone, so an
 * admin's own block never gets in the way of moderating.
 */
final class HiddenPlayers implements ResetInterface
{
    private bool $resolved = false;

    private null|string $viewerId = null;

    /** @var list<string> */
    private array $ids = [];

    public function __construct(
        readonly private ApiViewerRelations $apiViewerRelations,
        readonly private Security $security,
        readonly private RequestStack $requestStack,
        /**
         * A closure, not the service: RetrieveLoggedUserProfile -> GetPlayerProfile -> this class.
         *
         * @var Closure(): RetrieveLoggedUserProfile
         */
        #[AutowireServiceClosure(RetrieveLoggedUserProfile::class)]
        readonly private Closure $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $this->resolve();

        return $this->ids;
    }

    public function isHidden(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        return in_array(strtolower($playerId), $this->ids(), true);
    }

    /**
     * WHERE fragment (leading " AND", or empty) keeping rows whose player column is none of the
     * hidden players. NULL passes, so it is safe on LEFT JOINed columns. `$column` must be a uuid
     * expression - cast JSON text (`(elem ->> 'player_id')::uuid`) before handing it over.
     */
    public function sqlExclude(string $column): string
    {
        return self::sqlExcludeFor($column, $this->ids());
    }

    /**
     * sqlExclude() for explicit hidden ids instead of the request's viewer - for code that works for another player
     * than the signed-in one (a realtime push runs in the sender's request or in an async consumer, docs/features/
     * navigation INB-02). Ids that are not uuids are dropped: they are inlined into the SQL.
     *
     * @param list<string> $hiddenIds
     */
    public static function sqlExcludeFor(string $column, array $hiddenIds): string
    {
        $ids = self::validIds($hiddenIds);

        if ($ids === []) {
            return '';
        }

        $list = implode(', ', array_map(static fn (string $id): string => "'{$id}'::uuid", $ids));

        return " AND ({$column} IS NULL OR {$column} NOT IN ({$list}))";
    }

    /**
     * WHERE fragment (leading " AND", or empty) dropping pair/team times a hidden player took part
     * in - unless the viewer took part too: their own history stays whole. `$teamColumn` is the
     * `puzzle_solving_time.team` JSON column.
     */
    public function sqlExcludeTeam(string $teamColumn): string
    {
        $ids = $this->ids();

        return self::sqlExcludeTeamFor($teamColumn, $ids, $this->viewerId);
    }

    /**
     * sqlExcludeTeam() for explicit hidden ids and the player they are hidden from (see sqlExcludeFor()).
     *
     * @param list<string> $hiddenIds
     */
    public static function sqlExcludeTeamFor(string $teamColumn, array $hiddenIds, null|string $viewerId): string
    {
        $ids = self::validIds($hiddenIds);

        if ($ids === []) {
            return '';
        }

        $members = implode(', ', array_map(
            static fn (string $id): string => "'[{\"player_id\": \"{$id}\"}]'::jsonb",
            $ids,
        ));

        $viewerId = $viewerId !== null && Uuid::isValid($viewerId) ? strtolower($viewerId) : null;
        $puzzlers = "({$teamColumn}::jsonb -> 'puzzlers')";
        $viewerTookPart = $viewerId === null
            ? ''
            : " OR {$puzzlers} @> '[{\"player_id\": \"{$viewerId}\"}]'::jsonb";

        return " AND ({$teamColumn} IS NULL OR NOT ({$puzzlers} @> ANY (ARRAY[{$members}])){$viewerTookPart})";
    }

    /**
     * sqlNotificationNotHiddenFor() for the request's viewer.
     */
    public function sqlNotificationNotHidden(): string
    {
        $ids = $this->ids();

        return self::sqlNotificationNotHiddenFor($ids, $this->viewerId);
    }

    /**
     * The rule of the hiding branches of GetNotifications::forPlayer(), for the statements that read the notification
     * table alone (the unread counts, the Inbox badge's For you part): a badge must not count what the list leaves out.
     * Empty when nobody is hidden. `$viewerId` = the notifications' owner.
     *
     * - the actor (`actor_player_id`) of any row: the list's actor branches (pair/team renamed, guest links) and its
     *   solving time branch (the group edit's editor) leave out a row whose actor is hidden - also when the block came
     *   after the row was created (docs/features/navigation INB-02 review F1);
     * - a solving time row by its tracker and its pair/team members, a conversation request by its initiator.
     *
     * @param list<string> $hiddenIds
     */
    public static function sqlNotificationNotHiddenFor(array $hiddenIds, null|string $viewerId): string
    {
        if (self::validIds($hiddenIds) === []) {
            return '';
        }

        $actorNotHidden = self::sqlExcludeFor('notification.actor_player_id', $hiddenIds);
        $solvingTimeNotHidden = self::sqlExcludeFor('COALESCE(notification.actor_player_id, pst.player_id)', $hiddenIds)
            . self::sqlExcludeTeamFor('pst.team', $hiddenIds, $viewerId);
        $initiatorNotHidden = self::sqlExcludeFor('conv.initiator_id', $hiddenIds);

        return <<<SQL
{$actorNotHidden}
    AND (notification.target_solving_time_id IS NULL OR EXISTS (
        SELECT 1 FROM puzzle_solving_time pst WHERE pst.id = notification.target_solving_time_id{$solvingTimeNotHidden}
    ))
    AND (notification.target_conversation_id IS NULL OR EXISTS (
        SELECT 1 FROM conversation conv WHERE conv.id = notification.target_conversation_id{$initiatorNotHidden}
    ))
SQL;
    }

    /**
     * @param list<string> $ids
     * @return list<string>
     */
    private static function validIds(array $ids): array
    {
        $valid = [];

        foreach ($ids as $id) {
            // Inlined into SQL by the fragments above, so nothing but a uuid gets through
            if (Uuid::isValid($id)) {
                $valid[] = strtolower($id);
            }
        }

        return $valid;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->viewerId = null;
        $this->ids = [];
    }

    private function resolve(): void
    {
        if ($this->resolved === true) {
            return;
        }

        $this->resolved = true;

        if ($this->isAdminArea()) {
            return;
        }

        $user = $this->security->getUser();

        if ($user instanceof ApiUser) {
            // One query shared with the private-profile allow list (ApiViewerRelations)
            $apiViewerId = $user->getPlayer()->id->toString();
            $rows = array_map(
                static fn (string $blockedId): array => ['viewer_id' => $apiViewerId, 'blocked_id' => $blockedId],
                $this->apiViewerRelations->blockedIds(),
            );
        } elseif ($user instanceof UserAccount) {
            $profile = ($this->retrieveLoggedUserProfile)()->getProfile();
            $rows = array_map(
                static fn (string $blockedId): array => ['viewer_id' => $profile?->playerId, 'blocked_id' => $blockedId],
                $profile === null ? [] : $profile->hiddenPlayerIds,
            );
        } else {
            return;
        }

        foreach ($rows as $row) {
            $viewerId = $row['viewer_id'];
            $blockedId = $row['blocked_id'];

            // The ids are inlined into SQL by the fragments above, so nothing but a uuid gets through
            if ($viewerId === null || Uuid::isValid($viewerId) === false || Uuid::isValid($blockedId) === false) {
                continue;
            }

            $this->viewerId = strtolower($viewerId);
            $this->ids[] = strtolower($blockedId);
        }
    }

    private function isAdminArea(): bool
    {
        $request = $this->requestStack->getMainRequest();

        if ($request === null) {
            return false;
        }

        $path = $request->getPathInfo();

        return str_starts_with($path, '/admin') || str_starts_with($path, '/internal-api');
    }
}
