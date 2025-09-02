<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MembershipController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerMembership $getPlayerMembership,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
    ) {
    }

     #[Route(
         path: [
            'cs' => '/clenstvi/',
            'en' => '/en/membership',
            'es' => '/es/membresia',
            'ja' => '/ja/メンバーシップ',
            'fr' => '/fr/adhesion',
         ],
         name: 'membership',
     )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        try {
            $membership = $this->getPlayerMembership->byId($player->playerId);
        } catch (MembershipNotFound) {
            $membership = null;
        }

        return $this->render('membership.html.twig', [
            'membership' => $membership,
            'now' => $this->clock->now(),
        ]);
    }
}
