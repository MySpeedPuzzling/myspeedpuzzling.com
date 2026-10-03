<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\FindSimilarSpeedPuzzler;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\SimilarSpeedPuzzler;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Members' "Someone at your speed" in the add sheet of the compare page (docs/features/player-comparison.md D8): "Roll
 * the dice" picks a random player of similar skill (FindSimilarSpeedPuzzler) with the reasons why, "Add to line-up"
 * emits `comparisonAddSubject` (ref `p-<uuid>`) up to the page component, "Roll again" draws with a new seed.
 *
 * Lazy: nothing is queried until the first roll (seed null = the idle card). Each live action is its own request, so the
 * membership is checked on every one of them - a free account never gets a suggestion, whatever the props say.
 */
#[AsLiveComponent]
final class ComparisonSimilarSpeed
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    public const string EVENT_ADD_SUBJECT = 'comparisonAddSubject';

    /**
     * The viewer's Solo line-up - never suggested
     *
     * @var list<string>
     */
    #[LiveProp(updateFromParent: true)]
    public array $excludedPlayerIds = [];

    /**
     * Set server-side by roll(); the same seed keeps the same suggestion across re-renders. Null = not rolled yet.
     */
    #[LiveProp(writable: true)]
    public null|string $seed = null;

    // Who was just added, for the confirmation line of the idle card
    #[LiveProp]
    public null|string $addedPlayerName = null;

    private bool $candidateLoaded = false;

    private null|SimilarSpeedPuzzler $candidate = null;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private FindSimilarSpeedPuzzler $findSimilarSpeedPuzzler,
    ) {
    }

    #[LiveAction]
    public function roll(): void
    {
        $this->addedPlayerName = null;
        $this->seed = $this->member() !== null ? Uuid::uuid7()->toString() : null;
        $this->candidateLoaded = false;
    }

    #[LiveAction]
    public function add(): void
    {
        $candidate = $this->getCandidate();

        if ($candidate === null) {
            return;
        }

        $this->emitUp(self::EVENT_ADD_SUBJECT, ['ref' => ComparisonSubjectRef::player($candidate->playerId)->toString()]);

        // Back to the idle card; never the same person again, even before the page hands over the new line-up
        $this->excludedPlayerIds[] = $candidate->playerId;
        $this->addedPlayerName = $candidate->playerName ?? '#' . $candidate->playerCode;
        $this->seed = null;
        $this->candidateLoaded = false;
    }

    public function isMember(): bool
    {
        return $this->member() !== null;
    }

    public function hasRolled(): bool
    {
        return $this->seed !== null && $this->seed !== '' && $this->isMember();
    }

    public function getCandidate(): null|SimilarSpeedPuzzler
    {
        if ($this->candidateLoaded) {
            return $this->candidate;
        }

        $this->candidateLoaded = true;
        $profile = $this->member();

        if ($profile === null || $this->seed === null || $this->seed === '') {
            return $this->candidate = null;
        }

        return $this->candidate = $this->findSimilarSpeedPuzzler->find($profile->playerId, $this->excludedPlayerIds, $this->seed);
    }

    /**
     * "Top 21 %" of the viewer, for the skill reason (percentile 79.4 → 21)
     */
    public function viewerTopPercent(SimilarSpeedPuzzler $candidate): null|int
    {
        if ($candidate->viewerSkillPercentile === null) {
            return null;
        }

        return max(1, (int) ceil(100 - $candidate->viewerSkillPercentile));
    }

    private function member(): null|PlayerProfile
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $profile !== null && $profile->activeMembership ? $profile : null;
    }
}
