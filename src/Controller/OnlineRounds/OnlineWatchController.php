<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Results\CompetitionReference;
use SpeedPuzzling\Web\Results\RoundStopwatchState;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineWatchGate;
use SpeedPuzzling\Web\Services\RoundStopwatchLiveUpdates;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public watch page of a round's studio (docs/features/online-competitions/README.md §5.12, contract phase-1b.md
 * §5 `online_watch`): the event and the round, the round's clock as every phone shows it (read-only - the public
 * stopwatch topic), and the studio - the host and the guests on air - over WebRTC through a spectator token
 * (`online_watch_token`), drawn by assets/controllers/online_watch_controller.js.
 *
 * - Who may see it: OnlineWatchGate - anybody for a public event, only its editors for a draft, pending or rejected one
 *   (404 for everybody else), never for a round without online timing (404).
 * - No live video (LIVEKIT_* empty, or the round's video off): a page saying so, 200.
 * - `?obs=1`: the video alone on a transparent page, for an OBS browser source (docs/features/online-competitions/
 *   obs-youtube.md) - no bar, no clock, no texts in the way, the sound on without a tap.
 * - Never indexed, never in the sitemap (a prototype), never cached - it carries the clock as read now.
 */
final class OnlineWatchController extends AbstractController
{
    public function __construct(
        private readonly OnlineWatchGate $onlineWatchGate,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/sledovat',
            'en' => '/en/online-rounds/{roundId}/watch',
            'es' => '/es/online-rounds/{roundId}/watch',
            'ja' => '/ja/online-rounds/{roundId}/watch',
            'fr' => '/fr/online-rounds/{roundId}/watch',
            'de' => '/de/online-rounds/{roundId}/watch',
        ],
        name: 'online_watch',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $watch = $this->onlineWatchGate->open($roundId);
        $round = $watch->round;
        $competition = $round->competition;
        $roundId = $round->id->toString();
        $now = $this->clock->now();
        $timezone = $round->displayTimezone();

        $parameters = [
            'competition' => $competition,
            'round' => $round,
            'event' => new CompetitionReference(
                name: $competition->name,
                slug: $competition->slug,
                seriesName: $competition->series?->name,
                seriesSlug: $competition->series?->slug,
            ),
            'is_public' => $watch->isPublic,
            // The round's planned start in its own zone, the zone named - "18:00 Central European Time"
            'planned_start' => new EventTime($round->startsAt, $timezone, $round->isTimezoneAssumed()),
            'planned_day' => OccurrenceDates::localDay($round->startsAt, $timezone),
        ];

        $obs = $request->query->getBoolean('obs');

        if ($watch->videoAvailable === false) {
            $response = $this->render('online_rounds/watch_unavailable.html.twig', $parameters + ['obs' => $obs]);
        } else {
            $response = $this->render('online_rounds/watch.html.twig', $parameters + [
                'obs' => $obs,
                'stopwatch' => RoundStopwatchState::ofRound($round, $now),
                'stopwatch_topic' => RoundStopwatchLiveUpdates::topic($roundId),
                'youtube_url' => self::youtubeLink($watch->onlineRound->youtubeUrl),
            ]);
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * The organiser's YouTube stream as a link - only an https address on YouTube, anything else is not linked
     */
    public static function youtubeLink(null|string $url): null|string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');

        return $host === 'youtu.be' || $host === 'youtube.com' || str_ends_with($host, '.youtube.com') ? $url : null;
    }
}
