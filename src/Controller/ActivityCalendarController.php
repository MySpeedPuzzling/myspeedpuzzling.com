<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ActivityCalendarController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kalendar-aktivity/{playerId}',
            'en' => '/en/activity-calendar/{playerId}',
            'es' => '/es/calendario-actividad/{playerId}',
            'ja' => '/ja/アクティビティカレンダー/{playerId}',
            'fr' => '/fr/calendrier-activite/{playerId}',
            'de' => '/de/aktivitaets-kalender/{playerId}',
        ],
        name: 'activity_calendar',
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        $player = $this->getPlayerProfile->byId($playerId);

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($player->isPrivate && $loggedPlayerProfile?->playerId !== $player->playerId) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        $now = $this->clock->now();
        $currentYear = (int) $now->format('Y');
        $currentMonth = (int) $now->format('m');

        // Not getInt(): it answers a malformed value (?year=2025' - vulnerability scanners probe
        // these) with a 400 logged as an uncaught error. Malformed falls back like out-of-range.
        $year = $this->intQueryParameter($request, 'year') ?? $currentYear;
        $month = $this->intQueryParameter($request, 'month') ?? $currentMonth;

        if ($year < 2015 || $year > $currentYear) {
            $year = $currentYear;
        }

        if ($month < 1 || $month > 12) {
            $month = $currentMonth;
        }

        return $this->render('activity_calendar.html.twig', [
            'player' => $player,
            'year' => $year,
            'month' => $month,
        ]);
    }

    private function intQueryParameter(Request $request, string $key): null|int
    {
        $value = $request->query->all()[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    }
}
