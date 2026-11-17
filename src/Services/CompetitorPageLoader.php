<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitorPageNotFound;
use SpeedPuzzling\Web\Query\GetCompetitorList;
use SpeedPuzzling\Web\Query\GetCompetitorPage;
use SpeedPuzzling\Web\Results\CompetitorListView;
use SpeedPuzzling\Web\Results\CompetitorPageView;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\CompetitionTag;
use SpeedPuzzling\Web\Value\CompetitionTagKind;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * A competitor page for the current viewer (docs/features/competitions-management/competitor-pages.md) - shared by the
 * page and its `competitor_state` JSON, so both answer the same 404s: the route's kind letter and id (the frozen tag
 * kinds p|g|t|e, CompetitorPageUrl), a draft for anybody but its team, and everything GetCompetitorPage refuses.
 * The event's competitor list (list()) answers the same 404s: competitor pages off, an unknown event, a draft.
 */
final readonly class CompetitorPageLoader
{
    public const string KIND_REQUIREMENT = 'p|g|t|e';
    public const string ID_REQUIREMENT = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}|[1-9]\d{0,3}';

    public function __construct(
        private GetCompetitorPage $getCompetitorPage,
        private GetCompetitorList $getCompetitorList,
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private AuthorizationCheckerInterface $authorizationChecker,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitorPageNotFound
     */
    public function load(string $competitionId, string $kind, string $id): CompetitorPageView
    {
        $tagKind = CompetitionTagKind::tryFrom($kind);
        $isTable = preg_match('/^[1-9]\d{0,3}$/', $id) === 1;

        $tag = match (true) {
            $tagKind === CompetitionTagKind::Table && $isTable => CompetitionTag::table((int) $id),
            $tagKind === CompetitionTagKind::Participant && !$isTable => CompetitionTag::participant($id),
            $tagKind === CompetitionTagKind::Lineup && !$isTable => CompetitionTag::lineup($id),
            $tagKind === CompetitionTagKind::Entry && !$isTable => CompetitionTag::entry($id),
            default => throw new CompetitorPageNotFound(),
        };

        $view = $this->getCompetitorPage->of(
            $competitionId,
            $tag,
            $this->retrieveLoggedUserProfile->getProfile()?->playerId,
            $this->clock->now(),
        );

        $this->assertVisible($view->isDraft, $view->competitionId);

        return $view;
    }

    /**
     * @throws CompetitorPageNotFound
     */
    public function list(string $competitionId): CompetitorListView
    {
        $view = $this->getCompetitorList->of($competitionId, $this->retrieveLoggedUserProfile->getProfile()?->playerId);
        $this->assertVisible($view->isDraft, $view->competitionId);

        return $view;
    }

    /**
     * A draft exists only for its team and admins (docs/features/organizations/README.md "Drafts") - the voter is asked
     * only for a draft, so a public page pays no statement for it
     *
     * @throws CompetitorPageNotFound
     */
    private function assertVisible(bool $isDraft, string $competitionId): void
    {
        if ($isDraft && $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            throw new CompetitorPageNotFound();
        }
    }
}
