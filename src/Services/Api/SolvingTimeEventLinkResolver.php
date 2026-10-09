<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use ApiPlatform\Validator\Exception\ValidationException;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Value\CompetitionPick;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * The event link of POST and PUT /api/v1/me/solving-times (docs/features/events-page/high-frequency-series.md "API
 * v1"): `round_id` (POST only), `competition_id` (a one-time event or an edition - explicit) and `series_id` (a series
 * pick: MySpeedPuzzling finds the edition, else the time is a result of the series without one).
 *
 * Checked before anything is dispatched - the handlers save a time without a link they cannot use (the web form's
 * fallback), and their exceptions would reach the client wrapped: an unknown, malformed or not publicly visible id
 * (a draft, waiting for approval, rejected) is a 404, ids sent together that disagree are a 422 on the field.
 * Precedence `round_id` > `competition_id` > `series_id` - the most specific link wins and is explicit.
 */
final readonly class SolvingTimeEventLinkResolver
{
    public function __construct(
        private CompetitionRoundRepository $competitionRoundRepository,
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
    ) {
    }

    /**
     * What a new time is linked with besides its round - null when the round links it (the handler derives the
     * round's competition) or when nothing was sent.
     *
     * @throws CompetitionRoundNotFound
     * @throws CompetitionNotFound
     * @throws CompetitionSeriesNotFound
     * @throws ValidationException
     */
    public function forCreate(null|string $roundId, null|string $competitionId, null|string $seriesId): null|CompetitionPick
    {
        $round = $roundId !== null ? $this->publiclyVisibleRound($roundId) : null;
        $competition = $competitionId !== null ? $this->competition($competitionId, null) : null;
        $series = $seriesId !== null ? $this->series($seriesId, null) : null;

        if ($round !== null && $competition !== null && $round->competition->id->equals($competition->id) === false) {
            throw self::violation('competition_id', $competitionId, 'competition_id must be the competition of round_id.');
        }

        if ($series !== null) {
            self::assertEditionOf($competition ?? $round?->competition, $series, $seriesId);
        }

        if ($round !== null) {
            return null;
        }

        if ($competition !== null) {
            return self::explicit($competition);
        }

        return $series !== null ? CompetitionPick::series($series->id->toString()) : null;
    }

    /**
     * What an edited time is linked with. Neither id sent: the link it has, exactly - an explicit one stays explicit,
     * a series pick stays a series pick (the handler finds its edition again, like every edit); there is no way to
     * remove a link (P17). An id sent changes the link - the time's current competition or series is accepted even when
     * it is no longer publicly visible, like the edit form's include-current rule.
     *
     * @throws CompetitionNotFound
     * @throws CompetitionSeriesNotFound
     * @throws ValidationException
     */
    public function forUpdate(PuzzleSolvingTime $solvingTime, null|string $competitionId, null|string $seriesId): null|CompetitionPick
    {
        if ($competitionId === null && $seriesId === null) {
            if ($solvingTime->competitionSeries !== null) {
                return CompetitionPick::series($solvingTime->competitionSeries->id->toString());
            }

            return $solvingTime->competition !== null ? self::explicit($solvingTime->competition) : null;
        }

        $competition = $competitionId !== null ? $this->competition($competitionId, $solvingTime->competition) : null;
        $series = $seriesId !== null ? $this->series($seriesId, $solvingTime->competitionSeries ?? $solvingTime->competition?->series) : null;

        if ($series !== null) {
            self::assertEditionOf($competition, $series, $seriesId);
        }

        if ($competition !== null) {
            return self::explicit($competition);
        }

        return $series !== null ? CompetitionPick::series($series->id->toString()) : null;
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    private function publiclyVisibleRound(string $roundId): CompetitionRound
    {
        $round = $this->competitionRoundRepository->get($roundId);

        // A round of an event nobody may see does not exist for the API (docs/features/organizations/README.md
        // "Drafts"). The handler re-resolves the round and refuses the same - its 404 reaches the client unwrapped
        // (UnwrapHttpExceptionMiddleware)
        if ($this->isCompetitionPubliclyVisible->check($round->competition->id->toString()) === false) {
            throw new CompetitionRoundNotFound();
        }

        return $round;
    }

    /**
     * @throws CompetitionNotFound
     */
    private function competition(string $competitionId, null|Competition $current): Competition
    {
        $competition = $this->competitionRepository->get($competitionId);

        if ($current?->id->equals($competition->id) === true) {
            return $competition;
        }

        if ($this->isCompetitionPubliclyVisible->check($competition->id->toString()) === false) {
            throw new CompetitionNotFound();
        }

        return $competition;
    }

    /**
     * @throws CompetitionSeriesNotFound
     */
    private function series(string $seriesId, null|CompetitionSeries $current): CompetitionSeries
    {
        $series = $this->competitionSeriesRepository->get($seriesId);

        if ($series->isPubliclyVisible() === false && $current?->id->equals($series->id) !== true) {
            throw new CompetitionSeriesNotFound();
        }

        return $series;
    }

    /**
     * Sent together with a competition (or a round), the series must be the one the competition is an edition of
     *
     * @throws ValidationException
     */
    private static function assertEditionOf(null|Competition $competition, CompetitionSeries $series, null|string $seriesId): void
    {
        if ($competition === null || $competition->series?->id->equals($series->id) === true) {
            return;
        }

        throw self::violation('series_id', $seriesId, 'series_id must be the series the competition is an edition of.');
    }

    private static function explicit(Competition $competition): CompetitionPick
    {
        $competitionId = $competition->id->toString();

        return $competition->series !== null ? CompetitionPick::edition($competitionId) : CompetitionPick::event($competitionId);
    }

    private static function violation(string $field, null|string $value, string $message): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, null, [], null, $field, $value),
        ]));
    }
}
