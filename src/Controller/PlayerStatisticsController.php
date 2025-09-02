<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\Month;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PlayerStatisticsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private TranslatorInterface $translator,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/statistiky-hrace/{playerId}',
            'en' => '/en/player-statistics/{playerId}',
            'es' => '/es/estadisticas-jugador/{playerId}',
            'ja' => '/ja/プレイヤー統計/{playerId}',
            'fr' => '/fr/statistiques-joueur/{playerId}',
            'de' => '/de/spieler-statistiken/{playerId}',
        ],
        name: 'player_statistics',
    )]
    public function __invoke(
        Request $request,
        string $playerId,
        #[CurrentUser] null|UserInterface $user,
    ): Response {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('players');
        }

        $loggedPlayerProfile = $this->retrieveLoggedUserProfile->getProfile();

        if ($player->isPrivate && $loggedPlayerProfile?->playerId !== $player->playerId) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        $year = $request->query->getInt('year');
        $month = $request->query->getInt('month');

        $months = [];
        $years = [];
        $firstResultDate = $this->getPlayerSolvedPuzzles->getOldestResultDate($playerId);

        if ($firstResultDate !== null) {
            $months = $this->getMonthsSinceDate($firstResultDate);
            $years = $this->getYearsSinceDate($firstResultDate);
        }

        [$dateFrom, $dateTo] = $this->calculateDates($month, $year);

        $activeShowAll = $request->query->getBoolean('show-all');

        if ($activeShowAll === true) {
            $dateFrom = $firstResultDate ?? $dateFrom;
        }

        $activeYear = $year > 0 ? $year : null;

        if ($month === 0 && $year === 0 && $activeShowAll === false) {
            $now = new DateTimeImmutable();
            $month = (int) $now->format('m');
            $year = (int) $now->format('Y');
        }

        $activeMonth = new Month($month, $year);

        return $this->render('player_statistics.html.twig', [
            'player' => $player,
            'available_months' => $months,
            'available_years' => $years,
            'active_year' => $activeYear,
            'active_month' => $activeMonth,
            'active_show_all' => $activeShowAll,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    /**
     * @return array<Month>
     */
    private function getMonthsSinceDate(DateTimeImmutable $sinceDate): array
    {
        $currentDate = new DateTimeImmutable('first day of this month');
        $months = [];

        $iteratorDate = $currentDate;

        while ($iteratorDate >= $sinceDate->modify('first day of this month')) {
            $months[] = new Month((int) $iteratorDate->format('m'), (int) $iteratorDate->format('Y'));
            $iteratorDate = $iteratorDate->modify('-1 month');
        }

        return $months;
    }

    /**
     * @return array<int>
     */
    private function getYearsSinceDate(DateTimeImmutable $sinceDate): array
    {
        $currentYear = (int) (new DateTimeImmutable())->format('Y');
        $sinceYear = (int) $sinceDate->format('Y');

        $years = [];

        for ($year = $currentYear; $year >= $sinceYear; $year--) {
            $years[] = $year;
        }

        return $years;
    }

    /**
     * @return array<DateTimeImmutable>
     */
    private function calculateDates(int $month, int $year): array
    {
        $currentDate = new DateTimeImmutable();
        $currentYear = (int) $currentDate->format('Y');
        $currentMonth = (int) $currentDate->format('m');

        $isYearValid = $year >= 2015 && $year <= $currentYear;
        $isMonthValid = $month >= 1 && $month <= 12;

        if ($isYearValid && $isMonthValid) {
            // Both year and month provided and valid
            $dateFrom = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $dateTo = $dateFrom->modify('last day of this month')->setTime(23, 59, 59);
        } elseif ($isYearValid) {
            // Only year provided and valid
            $dateFrom = new DateTimeImmutable(sprintf('%04d-01-01', $year));
            $dateTo = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year));
        } else {
            // Neither provided nor invalid, use current month and year
            $dateFrom = new DateTimeImmutable(sprintf('%04d-%02d-01', $currentYear, $currentMonth));
            $dateTo = $dateFrom->modify('last day of this month')->setTime(23, 59, 59);
        }

        return [$dateFrom, $dateTo];
    }
}
