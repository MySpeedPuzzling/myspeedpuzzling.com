<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPlayersPerCountry;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LadderController extends AbstractController
{
    public function __construct(
        readonly private GetPlayersPerCountry $getPlayersPerCountry,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zebricek',
            'en' => '/en/ladder',
            'es' => '/es/escalera',
            'ja' => '/ja/リーダーボード',
            'fr' => '/fr/classement',
            'de' => '/de/rangliste',
        ],
        name: 'ladder',
    )]
    #[Route(
        path: [
            'cs' => '/zebricek/zeme/{countryCode}',
            'en' => '/en/ladder/country/{countryCode}',
            'es' => '/es/escalera/pais/{countryCode}',
            'ja' => '/ja/リーダーボード/国/{countryCode}',
            'fr' => '/fr/classement/pays/{countryCode}',
            'de' => '/de/rangliste/land/{countryCode}',
        ],
        name: 'ladder_country',
    )]
    public function __invoke(null|string $countryCode, #[CurrentUser] null|User $user): Response
    {
        if ($countryCode !== null) {
            $countryCode = CountryCode::fromCode($countryCode);

            if ($countryCode === null) {
                throw $this->createNotFoundException();
            }

            $player = $this->retrieveLoggedUserProfile->getProfile();

            if ($player === null || $player->activeMembership !== true) {
                $this->addFlash('warning', $this->translator->trans('flashes.exclusive_membership_feature'));

                return $this->redirectToRoute('ladder');
            }
        }

        return $this->render('ladder.html.twig', [
            'countries' => $this->getPlayersPerCountry->count(),
            'active_country' => $countryCode,
        ]);
    }
}
