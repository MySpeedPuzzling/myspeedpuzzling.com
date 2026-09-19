<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Closure;
use Doctrine\DBAL\Connection;
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
        readonly private Connection $database,
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
        $ids = $this->ids();

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

        if ($ids === []) {
            return '';
        }

        $members = implode(', ', array_map(
            static fn (string $id): string => "'[{\"player_id\": \"{$id}\"}]'::jsonb",
            $ids,
        ));

        $puzzlers = "({$teamColumn}::jsonb -> 'puzzlers')";
        $viewerTookPart = $this->viewerId === null
            ? ''
            : " OR {$puzzlers} @> '[{\"player_id\": \"{$this->viewerId}\"}]'::jsonb";

        return " AND ({$teamColumn} IS NULL OR NOT ({$puzzlers} @> ANY (ARRAY[{$members}])){$viewerTookPart})";
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
            $rows = $this->database
                ->executeQuery(
                    'SELECT blocker_id AS viewer_id, blocked_id FROM user_block WHERE blocker_id = :playerId',
                    ['playerId' => $user->getPlayer()->id->toString()],
                )
                ->fetchAllAssociative();
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
            if (is_string($viewerId) === false || is_string($blockedId) === false) {
                continue;
            }

            if (Uuid::isValid($viewerId) === false || Uuid::isValid($blockedId) === false) {
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
