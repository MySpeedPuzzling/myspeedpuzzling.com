<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Query\GetFastestPlayers;
use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LadderPerPiecesController extends AbstractController
{
    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
    ) {
    }

    #[Route(path: ['cs' => '/zebricek/jednotlivci/500-dilku', 'en' => '/en/ladder/solo/500-pieces', 'es' => '/es/escalera/individual/500-piezas', 'ja' => '/ja/リーダーボード/個人/500ピース', 'fr' => '/fr/classement/solo/500-pieces', 'de' => '/de/rangliste/einzelspieler/500-teile'], name: 'ladder_solo_500_pieces')]
    #[Route(path: ['cs' => '/zebricek/jednotlivci/1000-dilku', 'en' => '/en/ladder/solo/1000-pieces', 'es' => '/es/escalera/individual/1000-piezas', 'ja' => '/ja/リーダーボード/個人/1000ピース', 'fr' => '/fr/classement/solo/1000-pieces', 'de' => '/de/rangliste/einzelspieler/1000-teile'], name: 'ladder_solo_1000_pieces')]
    #[Route(path: ['cs' => '/zebricek/pary/500-dilku', 'en' => '/en/ladder/pairs/500-pieces', 'es' => '/es/escalera/parejas/500-piezas', 'ja' => '/ja/リーダーボード/ペア/500ピース', 'fr' => '/fr/classement/paires/500-pieces', 'de' => '/de/rangliste/paare/500-teile'], name: 'ladder_pairs_500_pieces')]
    #[Route(path: ['cs' => '/zebricek/pary/1000-dilku', 'en' => '/en/ladder/pairs/1000-pieces', 'es' => '/es/escalera/parejas/1000-piezas', 'ja' => '/ja/リーダーボード/ペア/1000ピース', 'fr' => '/fr/classement/paires/1000-pieces', 'de' => '/de/rangliste/paare/1000-teile'], name: 'ladder_pairs_1000_pieces')]
    #[Route(path: ['cs' => '/zebricek/skupiny/500-dilku', 'en' => '/en/ladder/groups/500-pieces', 'es' => '/es/escalera/grupos/500-piezas', 'ja' => '/ja/リーダーボード/グループ/500ピース', 'fr' => '/fr/classement/groupes/500-pieces', 'de' => '/de/rangliste/gruppen/500-teile'], name: 'ladder_groups_500_pieces')]
    #[Route(path: ['cs' => '/zebricek/skupiny/1000-dilku', 'en' => '/en/ladder/groups/1000-pieces', 'es' => '/es/escalera/grupos/1000-piezas', 'ja' => '/ja/リーダーボード/グループ/1000ピース', 'fr' => '/fr/classement/groupes/1000-pieces', 'de' => '/de/rangliste/gruppen/1000-teile'], name: 'ladder_groups_1000_pieces')]
    #[Route(path: ['cs' => '/zebricek/jednotlivci/500-dilku/zeme/{countryCode}', 'en' => '/en/ladder/solo/500-pieces/country/{countryCode}', 'es' => '/es/escalera/individual/500-piezas/pais/{countryCode}', 'ja' => '/ja/リーダーボード/個人/500ピース/国/{countryCode}', 'fr' => '/fr/classement/solo/500-pieces/pays/{countryCode}', 'de' => '/de/rangliste/einzelspieler/500-teile/land/{countryCode}'], name: 'ladder_solo_500_pieces_country')]
    #[Route(path: ['cs' => '/zebricek/jednotlivci/1000-dilku/zeme/{countryCode}', 'en' => '/en/ladder/solo/1000-pieces/country/{countryCode}', 'es' => '/es/escalera/individual/1000-piezas/pais/{countryCode}', 'ja' => '/ja/リーダーボード/個人/1000ピース/国/{countryCode}', 'fr' => '/fr/classement/solo/1000-pieces/pays/{countryCode}', 'de' => '/de/rangliste/einzelspieler/1000-teile/land/{countryCode}'], name: 'ladder_solo_1000_pieces_country')]
    #[Route(path: ['cs' => '/zebricek/pary/500-dilku/zeme/{countryCode}', 'en' => '/en/ladder/pairs/500-pieces/country/{countryCode}', 'es' => '/es/escalera/parejas/500-piezas/pais/{countryCode}', 'ja' => '/ja/リーダーボード/ペア/500ピース/国/{countryCode}', 'fr' => '/fr/classement/paires/500-pieces/pays/{countryCode}', 'de' => '/de/rangliste/paare/500-teile/land/{countryCode}'], name: 'ladder_pairs_500_pieces_country')]
    #[Route(path: ['cs' => '/zebricek/pary/1000-dilku/zeme/{countryCode}', 'en' => '/en/ladder/pairs/1000-pieces/country/{countryCode}', 'es' => '/es/escalera/parejas/1000-piezas/pais/{countryCode}', 'ja' => '/ja/リーダーボード/ペア/1000ピース/国/{countryCode}', 'fr' => '/fr/classement/paires/1000-pieces/pays/{countryCode}', 'de' => '/de/rangliste/paare/1000-teile/land/{countryCode}'], name: 'ladder_pairs_1000_pieces_country')]
    #[Route(path: ['cs' => '/zebricek/skupiny/500-dilku/zeme/{countryCode}', 'en' => '/en/ladder/groups/500-pieces/country/{countryCode}', 'es' => '/es/escalera/grupos/500-piezas/pais/{countryCode}', 'ja' => '/ja/リーダーボード/グループ/500ピース/国/{countryCode}', 'fr' => '/fr/classement/groupes/500-pieces/pays/{countryCode}', 'de' => '/de/rangliste/gruppen/500-teile/land/{countryCode}'], name: 'ladder_groups_500_pieces_country')]
    #[Route(path: ['cs' => '/zebricek/skupiny/1000-dilku/zeme/{countryCode}', 'en' => '/en/ladder/groups/1000-pieces/country/{countryCode}', 'es' => '/es/escalera/grupos/1000-piezas/pais/{countryCode}', 'ja' => '/ja/リーダーボード/グループ/1000ピース/国/{countryCode}', 'fr' => '/fr/classement/groupes/1000-pieces/pays/{countryCode}', 'de' => '/de/rangliste/gruppen/1000-teile/land/{countryCode}'], name: 'ladder_groups_1000_pieces_country')]
    public function __invoke(Request $request, null|string $countryCode): Response
    {
        /** @var string $routeName */
        $routeName = $request->attributes->get('_route');

        if ($countryCode !== null) {
            $countryCode = CountryCode::fromCode($countryCode);

            if ($countryCode === null) {
                throw $this->createNotFoundException();
            }
        }

        $solvedPuzzleTimes = match (true) {
            str_starts_with($routeName, 'ladder_solo_500_pieces') => $this->getFastestPlayers->perPiecesCount(500, 100, $countryCode),
            str_starts_with($routeName, 'ladder_solo_1000_pieces') => $this->getFastestPlayers->perPiecesCount(1000, 100, $countryCode),
            str_starts_with($routeName, 'ladder_pairs_500_pieces') => $this->getFastestPairs->perPiecesCount(500, 100, $countryCode),
            str_starts_with($routeName, 'ladder_pairs_1000_pieces') => $this->getFastestPairs->perPiecesCount(1000, 100, $countryCode),
            str_starts_with($routeName, 'ladder_groups_500_pieces') => $this->getFastestGroups->perPiecesCount(500, 100, $countryCode),
            str_starts_with($routeName, 'ladder_groups_1000_pieces') => $this->getFastestGroups->perPiecesCount(1000, 100, $countryCode),
            default => throw $this->createNotFoundException(),
        };

        return $this->render('ladder_per_pieces.html.twig', [
            'countries' => $this->getPlayersPerCountry->count(),
            'active_country' => $countryCode,
            'solved_puzzle_times' => $solvedPuzzleTimes,
        ]);
    }
}
