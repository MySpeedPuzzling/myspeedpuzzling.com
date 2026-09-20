<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * An API token's player has no profile loaded on every request the way a website visitor has, so
 * what the blocklist (HiddenPlayers) and the private-profile allow list (PrivateProfileAccess)
 * need to know about them comes from here - ONE query for both, once per request, lazily.
 */
final class ApiViewerRelations implements ResetInterface
{
    private bool $resolved = false;

    /** @var list<string> */
    private array $blockedIds = [];

    /** @var list<string> */
    private array $revealedIds = [];

    public function __construct(
        readonly private Connection $database,
        readonly private Security $security,
    ) {
    }

    /**
     * @return list<string>
     */
    public function blockedIds(): array
    {
        $this->resolve();

        return $this->blockedIds;
    }

    /**
     * @return list<string>
     */
    public function revealedIds(): array
    {
        $this->resolve();

        return $this->revealedIds;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->blockedIds = [];
        $this->revealedIds = [];
    }

    private function resolve(): void
    {
        if ($this->resolved === true) {
            return;
        }

        $this->resolved = true;

        $user = $this->security->getUser();

        if (!$user instanceof ApiUser) {
            return;
        }

        $revealedIds = PrivateProfileAccess::sqlRevealedIdsOf('player');

        $row = $this->database
            ->executeQuery(
                <<<SQL
SELECT
    (SELECT json_agg(user_block.blocked_id) FROM user_block WHERE user_block.blocker_id = player.id) AS blocked_ids,
    {$revealedIds} AS revealed_ids
FROM player
WHERE player.id = :playerId
SQL,
                ['playerId' => $user->getPlayer()->id->toString()],
            )
            ->fetchAssociative();

        if ($row === false) {
            return;
        }

        $this->blockedIds = self::decode($row['blocked_ids']);
        $this->revealedIds = self::decode($row['revealed_ids']);
    }

    /**
     * @return list<string>
     */
    private static function decode(mixed $json): array
    {
        if (is_string($json) === false) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
}
