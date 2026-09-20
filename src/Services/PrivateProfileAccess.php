<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Closure;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The private players who let the current viewer see them - see
 * docs/features/private-profile-allow-list.md.
 *
 * This is the ONLY code that may ever unmask a private player. Read-side queries select
 * {@see self::sqlIsPrivate()} instead of the raw `is_private` column, so "is private" everywhere
 * downstream (results, templates, API) means "hidden from this viewer". Anything that does not go
 * through here keeps hiding - every omission fails towards privacy.
 *
 * Nearly every viewer is on nobody's allow list, and then the fragment is the bare column: the
 * query text and its plan are exactly what they were before the feature existed. The ids ride on
 * the viewer's own profile, which every signed-in web request loads anyway, or share the
 * blocklist's one lookup for an API token (ApiViewerRelations) - the feature costs no query of
 * its own. No signed-in viewer (guests, cron, async consumers,
 * client-credentials API tokens) means nobody is revealed - guest pages are shared-cached and
 * must never depend on anyone's allow list.
 */
final class PrivateProfileAccess implements ResetInterface
{
    private bool $resolved = false;

    /** @var list<string> */
    private array $ids = [];

    public function __construct(
        readonly private Security $security,
        /**
         * A closure, not the service: RetrieveLoggedUserProfile -> GetPlayerProfile -> this class.
         *
         * @var Closure(): RetrieveLoggedUserProfile
         */
        #[AutowireServiceClosure(RetrieveLoggedUserProfile::class)]
        readonly private Closure $retrieveLoggedUserProfile,
        readonly private ApiViewerRelations $apiViewerRelations,
    ) {
    }

    /**
     * @return list<string>
     */
    public function revealedIds(): array
    {
        $this->resolve();

        return $this->ids;
    }

    public function isRevealed(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        return in_array(strtolower($playerId), $this->revealedIds(), true);
    }

    /**
     * True once a request has asked and the viewer turned out to be on somebody's allow list: what
     * was rendered may name a private player, so the response must never be shared.
     */
    public function hasRevealedSomebody(): bool
    {
        return $this->resolved === true && $this->ids !== [];
    }

    /**
     * Boolean SQL expression: is the player behind `$alias` (a `player` table alias) hidden from
     * the current viewer? NULL for a NULL row, exactly like the column, so LEFT JOINs behave as before.
     */
    public function sqlIsPrivate(string $alias): string
    {
        $ids = $this->revealedIds();

        if ($ids === []) {
            return "{$alias}.is_private";
        }

        return "({$alias}.is_private AND {$alias}.id NOT IN ({$this->inlined($ids)}))";
    }

    /**
     * The opposite, for WHERE clauses that drop private players from a personal list (a feed, the
     * viewer's favourites). Global rankings keep the bare `is_private = false` - nobody is ranked
     * differently for different viewers.
     */
    public function sqlIsPublic(string $alias): string
    {
        $ids = $this->revealedIds();

        if ($ids === []) {
            return "{$alias}.is_private = false";
        }

        return "({$alias}.is_private = false OR {$alias}.id IN ({$this->inlined($ids)}))";
    }

    /**
     * The subquery both profile lookups select for the viewer's own row. A block in either
     * direction outranks the allow list, whoever wrote either row and when.
     */
    public static function sqlRevealedIdsOf(string $viewerAlias): string
    {
        return <<<SQL
(
        SELECT json_agg(private_profile_viewer.owner_id)
        FROM private_profile_viewer
        WHERE private_profile_viewer.viewer_id = {$viewerAlias}.id
            AND private_profile_viewer.owner_id <> {$viewerAlias}.id
            AND NOT EXISTS (
                SELECT 1 FROM user_block
                WHERE (user_block.blocker_id = private_profile_viewer.owner_id AND user_block.blocked_id = {$viewerAlias}.id)
                    OR (user_block.blocker_id = {$viewerAlias}.id AND user_block.blocked_id = private_profile_viewer.owner_id)
            )
    )
SQL;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->ids = [];
    }

    /**
     * @param list<string> $ids
     */
    private function inlined(array $ids): string
    {
        return implode(', ', array_map(static fn (string $id): string => "'{$id}'::uuid", $ids));
    }

    private function resolve(): void
    {
        if ($this->resolved === true) {
            return;
        }

        $this->resolved = true;

        $user = $this->security->getUser();

        if ($user instanceof ApiUser) {
            $ownerIds = $this->apiViewerRelations->revealedIds();
        } elseif ($user instanceof UserAccount) {
            $profile = ($this->retrieveLoggedUserProfile)()->getProfile();
            $ownerIds = $profile === null ? [] : $profile->revealedPrivatePlayerIds;
        } else {
            return;
        }

        foreach ($ownerIds as $ownerId) {
            // The ids are inlined into SQL by the fragments above, so nothing but a uuid gets through
            if (Uuid::isValid($ownerId) === false) {
                continue;
            }

            $this->ids[] = strtolower($ownerId);
        }
    }
}
