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

final class LadderController extends AbstractController
{
    public function __construct(
        readonly private GetFastestPlayers $getFastestPlayers,
        readonly private GetFastestPairs $getFastestPairs,
        readonly private GetFastestGroups $getFastestGroups,
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zebricek',
            'en' => '/en/ladder',
        ],
        name: 'ladder',
    )]
    #[Route(
        path: [
            'cs' => '/zebricek/zeme/{countryCode}',
            'en' => '/en/ladder/country/{countryCode}',
        ],
        name: 'ladder_country',
    )]
    public function __invoke(Request $request, null|string $countryCode): Response
    {
        if ($countryCode !== null) {
            $countryCode = CountryCode::fromCode($countryCode);

            if ($countryCode === null) {
                throw $this->createNotFoundException();
            }
        }

        return $this->render('ladder.html.twig', [
            'countries' => $this->getPlayersPerCountry->count(),
            'active_country' => $countryCode,
            'fastest_players_500_pieces' => $this->getFastestPlayers->perPiecesCount(500, 10, $countryCode),
            'fastest_players_1000_pieces' => $this->getFastestPlayers->perPiecesCount(1000, 10, $countryCode),
            'fastest_pairs_500_pieces' => $this->getFastestPairs->perPiecesCount(500, 10, $countryCode),
            'fastest_pairs_1000_pieces' => $this->getFastestPairs->perPiecesCount(1000, 10, $countryCode),
            'fastest_groups_500_pieces' => $this->getFastestGroups->perPiecesCount(500, 10, $countryCode),
            'fastest_groups_1000_pieces' => $this->getFastestGroups->perPiecesCount(1000, 10, $countryCode),
        ]);
    }
}
