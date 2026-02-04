<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MembershipController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerMembership $getPlayerMembership,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
        readonly private PlayerRepository $playerRepository,
    ) {
    }

     #[Route(
         path: [
            'cs' => '/clenstvi/',
            'en' => '/en/membership',
            'es' => '/es/membresia',
            'ja' => '/ja/メンバーシップ',
            'fr' => '/fr/adhesion',
            'de' => '/de/mitgliedschaft',
         ],
         name: 'membership',
     )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        $player = $this->playerRepository->get($profile->playerId);

        try {
            $membership = $this->getPlayerMembership->byId($profile->playerId);
        } catch (MembershipNotFound) {
            $membership = null;
        }

        return $this->render('membership.html.twig', [
            'membership' => $membership,
            'player' => $player,
            'now' => $this->clock->now(),
        ]);
    }
}
