<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionReferees;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * What the referees page shows (live-results.md "Referees") - shared by the page and the controller that answers a
 * refused removal with the page again.
 */
final readonly class CompetitionRefereesPage
{
    public const string TEMPLATE = 'referees/competition_referees.html.twig';
    public const string CSRF_TOKEN_ID = 'competition-referee-remove';

    public function __construct(
        private GetCompetitionEvents $getCompetitionEvents,
        private GetCompetitionReferees $getCompetitionReferees,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws CompetitionNotFound
     */
    public function parameters(string $competitionId, string $locale): array
    {
        $competition = $this->getCompetitionEvents->byId($competitionId);

        return [
            'competition' => $competition,
            'referees' => $this->getCompetitionReferees->ofCompetition($competition->id),
            'referees_link' => self::refereesLink($this->urlGenerator, $competition->id, $locale),
        ];
    }

    /**
     * The link to hand the referees: the event's live entry, always on the current round (`live_results_event`).
     */
    public static function refereesLink(UrlGeneratorInterface $urlGenerator, string $competitionId, string $locale): string
    {
        return $urlGenerator->generate('live_results_event', [
            '_locale' => $locale,
            'competitionId' => $competitionId,
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
