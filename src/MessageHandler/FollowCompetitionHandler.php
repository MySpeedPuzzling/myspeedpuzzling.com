<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FollowedCompetition;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Exceptions\FollowTargetNotAvailable;
use SpeedPuzzling\Web\Exceptions\OrganizationNotFound;
use SpeedPuzzling\Web\Message\FollowCompetition;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Query\IsOrganizationPubliclyVisible;
use SpeedPuzzling\Web\Query\IsSeriesPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\FollowedCompetitionRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\FollowTarget;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A star on the events page (docs/features/events-page/README.md, "Follow"): a publicly visible one-time event, series
 * or organization (docs/features/organizations/README.md - never a draft). An edition is followed through its series.
 * Following twice keeps one row.
 */
#[AsMessageHandler]
readonly final class FollowCompetitionHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private FollowedCompetitionRepository $followedCompetitionRepository,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private IsSeriesPubliclyVisible $isSeriesPubliclyVisible,
        private IsOrganizationPubliclyVisible $isOrganizationPubliclyVisible,
        private OrganizationRepository $organizationRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws FollowTargetNotAvailable
     */
    public function __invoke(FollowCompetition $message): void
    {
        $target = FollowTarget::tryFromString($message->target) ?? throw new FollowTargetNotAvailable();
        $player = $this->playerRepository->get($message->playerId);

        if ($this->followedCompetitionRepository->find($player, $target) !== null) {
            return;
        }

        if ($target->isOrganization()) {
            if ($this->isOrganizationPubliclyVisible->check($target->id) === false) {
                throw new FollowTargetNotAvailable();
            }

            try {
                $organization = $this->organizationRepository->get($target->id);
            } catch (OrganizationNotFound) {
                throw new FollowTargetNotAvailable();
            }

            $this->followedCompetitionRepository->save(
                FollowedCompetition::ofOrganization(Uuid::uuid7(), $player, $organization, $this->clock->now()),
            );

            return;
        }

        if ($target->isSeries()) {
            try {
                $series = $this->competitionSeriesRepository->get($target->id);
            } catch (CompetitionSeriesNotFound) {
                throw new FollowTargetNotAvailable();
            }

            if ($this->isSeriesPubliclyVisible->check($target->id) === false) {
                throw new FollowTargetNotAvailable();
            }

            $this->followedCompetitionRepository->save(
                FollowedCompetition::ofSeries(Uuid::uuid7(), $player, $series, $this->clock->now()),
            );

            return;
        }

        try {
            $competition = $this->competitionRepository->get($target->id);
        } catch (CompetitionNotFound) {
            throw new FollowTargetNotAvailable();
        }

        if ($competition->series !== null || $this->isCompetitionPubliclyVisible->check($target->id) === false) {
            throw new FollowTargetNotAvailable();
        }

        $this->followedCompetitionRepository->save(
            FollowedCompetition::ofCompetition(Uuid::uuid7(), $player, $competition, $this->clock->now()),
        );
    }
}
