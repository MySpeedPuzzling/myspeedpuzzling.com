<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PlayersPerCountryController extends AbstractController
{
    public function __construct(
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/hraci-dle-zeme/{countryCode}',
            'en' => '/en/players-from-country/{countryCode}',
            'es' => '/es/jugadores-del-pais/{countryCode}',
        ],
        name: 'players_per_country',
    )]
    public function __invoke(string $countryCode, #[CurrentUser] null|UserInterface $user): Response
    {
        $code = CountryCode::fromCode($countryCode);

        if ($code === null) {
            throw $this->createNotFoundException();
        }

        return $this->render('players_per_country.html.twig', [
            'players' => $this->getPlayersPerCountry->byCountry($code),
            'country' => $code,
        ]);
    }
}
