<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Query\GetCompetitionDisplayState;
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
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws CompetitionDisplayNotFound
     */
    public function forToken(string $token): array
    {
        $state = $this->getCompetitionDisplayState->forToken($token);
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
        ];

        $serverNow = $this->clock->now();

        return [
            ...$state->jsonSerialize(),
            'texts' => $texts,
            'mercure' => $this->officialResultsSubscription->forDisplay($state->competitionId, $state->followedRoundIds),
            'serverNowMs' => RoundClock::milliseconds($serverNow),
        ];
    }
}
