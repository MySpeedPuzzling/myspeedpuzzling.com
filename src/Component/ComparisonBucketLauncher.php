<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetComparisonPlayers;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\ComparisonPlayer;
use SpeedPuzzling\Web\Services\ComparisonBucket;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The site-wide floating launcher (bottom-left FAB + dropup panel) that gives
 * quick access to the comparison bucket from any page.
 */
#[AsLiveComponent]
final class ComparisonBucketLauncher
{
    use DefaultActionTrait;

    private const int NON_MEMBER_SUBJECT_LIMIT = 2;

    #[LiveProp(writable: true)]
    public bool $open = false;

    #[LiveProp(writable: true)]
    public string $addQuery = '';

    public function __construct(
        private readonly ComparisonBucket $comparisonBucket,
        private readonly GetComparisonPlayers $getComparisonPlayers,
        private readonly SearchPlayers $searchPlayers,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    public function count(): int
    {
        return $this->comparisonBucket->count();
    }

    public function isMember(): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $profile !== null && $profile->activeMembership;
    }

    public function canAddMore(): bool
    {
        return $this->isMember() || $this->comparisonBucket->count() < self::NON_MEMBER_SUBJECT_LIMIT;
    }

    public function canAddSelf(): bool
    {
        $self = $this->retrieveLoggedUserProfile->getProfile();

        return $self !== null
            && $this->comparisonBucket->hasPlayer($self->playerId) === false
            && $this->canAddMore();
    }

    /**
     * @return list<ComparisonPlayer>
     */
    public function getSubjects(): array
    {
        $ids = $this->comparisonBucket->playerIds();
        $players = $this->getComparisonPlayers->byIds($ids);

        $result = [];

        foreach ($ids as $id) {
            if (isset($players[$id])) {
                $result[] = $players[$id];
            }
        }

        return $result;
    }

    /**
     * @return list<ComparisonPlayer>
     */
    public function getSearchResults(): array
    {
        $query = trim($this->addQuery);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $matches = $this->searchPlayers->fulltext($query, 6);
        $players = $this->getComparisonPlayers->byIds(array_map(static fn ($match): string => $match->playerId, $matches));
        $existing = $this->comparisonBucket->playerIds();
        $selfPlayerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

        $results = [];

        foreach ($matches as $match) {
            $player = $players[$match->playerId] ?? null;

            if ($player === null || in_array($player->playerId, $existing, true)) {
                continue;
            }

            if ($player->isPrivate && $player->playerId !== $selfPlayerId) {
                continue;
            }

            $results[] = $player;
        }

        return $results;
    }

    #[LiveAction]
    public function toggle(): void
    {
        $this->open = !$this->open;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->open = false;
    }

    #[LiveAction]
    public function addPlayer(#[LiveArg] string $id): void
    {
        if (Uuid::isValid($id) === false || $this->canAddMore() === false) {
            return;
        }

        $player = $this->getComparisonPlayers->byIds([$id])[$id] ?? null;

        if ($player === null) {
            return;
        }

        if ($player->isPrivate && $player->playerId !== $this->retrieveLoggedUserProfile->getProfile()?->playerId) {
            return;
        }

        $this->comparisonBucket->addPlayer($id);
        $this->addQuery = '';
    }

    #[LiveAction]
    public function addSelf(): void
    {
        $self = $this->retrieveLoggedUserProfile->getProfile();

        if ($self !== null && $this->canAddMore()) {
            $this->comparisonBucket->addPlayer($self->playerId);
        }
    }

    #[LiveAction]
    public function removePlayer(#[LiveArg] string $id): void
    {
        $this->comparisonBucket->removePlayer($id);
    }
}
