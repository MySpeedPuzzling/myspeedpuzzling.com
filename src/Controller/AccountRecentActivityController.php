<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Query\GetAuthAuditEvents;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The last 50 auth events for the logged-in account - a security feature, so
 * deliberately NOT membership-gated. "Someone tried to get in" is the point.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AccountRecentActivityController extends AbstractController
{
    public function __construct(
        private readonly GetAuthAuditEvents $getAuthAuditEvents,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/ucet/nedavna-aktivita',
            'en' => '/en/account/recent-activity',
            'es' => '/es/cuenta/actividad-reciente',
            'ja' => '/ja/アカウント/最近のアクティビティ',
            'fr' => '/fr/compte/activite-recente',
            'de' => '/de/konto/letzte-aktivitaet',
        ],
        name: 'account_recent_activity',
        methods: ['GET'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user): Response
    {
        if (!$user instanceof UserAccount) {
            // Window A: a legacy Auth0 session has no user_account row and thus no audit trail
            return $this->redirectToRoute('edit_profile');
        }

        return $this->render('account_recent_activity.html.twig', [
            'events' => $this->getAuthAuditEvents->recentForUserAccount($user->id->toString()),
        ]);
    }
}
