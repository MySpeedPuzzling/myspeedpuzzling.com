<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionDisplayState;
use SpeedPuzzling\Web\Results\CompetitionDisplayState;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The whole state answer of a competition display (D-displays-overlays.md §7.2) - `GET /display/{token}/state` and the
 * display page's first state (D4): GetCompetitionDisplayState's data, the renderer's own texts in the display's
 * language, the Mercure subscription (the event's display signals + the followed rounds' public stopwatch topics) and
 * the server's time read as late as possible, like the public stopwatch state endpoint.
 */
final readonly class CompetitionDisplayPayload
{
    public function __construct(
        private GetCompetitionDisplayState $getCompetitionDisplayState,
        private OfficialResultsSubscription $officialResultsSubscription,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
        private DisplayQrCodes $displayQrCodes,
        private DisplayRendererBuild $displayRendererBuild,
    ) {
    }

    /**
     * The display page's first picture (competition toolkit D4): the state answer and the stored design, from one read.
     *
     * @return array{state: array<string, mixed>, design: array<string, mixed>, competitionId: string, kind: string, locale: string}
     * @throws CompetitionDisplayNotFound
     */
    public function pageForToken(string $token): array
    {
        $state = $this->getCompetitionDisplayState->forToken($token);

        return [
            'state' => $this->answer($state),
            'design' => $state->design,
            'competitionId' => $state->competitionId,
            'kind' => $state->kind->value,
            'locale' => $state->locale,
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws CompetitionDisplayNotFound
     */
    public function forToken(string $token): array
    {
        return $this->answer($this->getCompetitionDisplayState->forToken($token));
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(CompetitionDisplayState $state): array
    {
        $locale = $state->locale;
        $texts = [
            'startsIn' => $this->translator->trans('competition.stopwatch.starts_in', ['%time%' => '%time%'], locale: $locale),
            'timesUp' => $this->translator->trans('competition.stopwatch.times_up', locale: $locale),
            'stopped' => $this->translator->trans('competition.stopwatch.stopped', locale: $locale),
            'notStarted' => $this->translator->trans('competition.stopwatch.not_started', locale: $locale),
            'scheduled' => $this->translator->trans('competition.stopwatch.scheduled', locale: $locale),
            'finished' => $this->translator->trans('competition_display.texts.finished', locale: $locale),
            'piecesPlaced' => $this->translator->trans('competition_display.texts.pieces_placed', ['%count%' => '%count%'], locale: $locale),
            'category' => $state->round !== null
                ? $this->translator->trans('competition.round.category.' . $state->round['category'], locale: $locale)
                : null,
            'table' => $this->translator->trans('competition_display.texts.table', ['%number%' => '%number%'], locale: $locale),
            'pieces' => $this->translator->trans('competition_display.texts.pieces', ['%count%' => '%count%'], locale: $locale),
            'invalid' => $this->translator->trans('competition_display.texts.invalid', locale: $locale),
        ];

        if ($state->standings !== null) {
            $texts = [...$texts, ...$this->standingTexts($state->standings, $locale)];
        }

        $serverNow = $this->clock->now();

        return [
            ...$state->jsonSerialize(),
            'texts' => $texts,
            'qr' => (object) $this->displayQrCodes->forState($state),
            'rendererBuild' => $this->displayRendererBuild->current(),
            'mercure' => $this->officialResultsSubscription->forDisplay($state->competitionId, $state->followedRoundIds),
            'serverNowMs' => RoundClock::milliseconds($serverNow),
        ];
    }

    /**
     * The standings element's texts for the editor's sample standing (assets/displays/sample_data.js sampleStanding():
     * 6 rounds, 12 pieces missing) in the display's language.
     *
     * @return array<string, mixed>
     */
    public function sampleStandingTexts(string $locale): array
    {
        return $this->standingTexts([
            'sample' => ['roundsTotal' => 6, 'rows' => [['totalMissingPieces' => 12]]],
        ], $locale);
    }

    /**
     * The standings element's texts (v2 S2) - the public standing page's own texts in the display's language, no new
     * translation. Plural texts are given per number the standings show (`standingFinal` per round count,
     * `standingMissing` per count of missing pieces): every locale has its own plural rules.
     *
     * @param array<string, null|array<string, mixed>> $standings
     * @return array<string, mixed>
     */
    private function standingTexts(array $standings, string $locale): array
    {
        $final = [];
        $missing = [];

        foreach ($standings as $standing) {
            if ($standing === null) {
                continue;
            }

            $roundsTotal = $standing['roundsTotal'] ?? null;

            if (is_int($roundsTotal)) {
                $final[(string) $roundsTotal] = $this->translator->trans('public_standing.final', ['%count%' => $roundsTotal], locale: $locale);
            }

            foreach (is_array($standing['rows'] ?? null) ? $standing['rows'] : [] as $row) {
                $count = is_array($row) ? ($row['totalMissingPieces'] ?? null) : null;

                if (is_int($count) && $count > 0) {
                    $missing[(string) $count] = $this->translator->trans('finished_missing.missing_short', ['%count%' => $count], locale: $locale);
                }
            }
        }

        return [
            'standingProvisional' => $this->translator->trans('public_standing.provisional', locale: $locale),
            'standingRoundsOf' => $this->translator->trans('public_standing.rounds_of', ['%scored%' => '%scored%', '%count%' => '%count%'], locale: $locale),
            'standingRoundShort' => $this->translator->trans('public_standing.round_short', ['%number%' => '%number%'], locale: $locale),
            'standingTotal' => $this->translator->trans('public_standing.total', locale: $locale),
            'standingFinal' => (object) $final,
            'standingMissing' => (object) $missing,
        ];
    }
}
