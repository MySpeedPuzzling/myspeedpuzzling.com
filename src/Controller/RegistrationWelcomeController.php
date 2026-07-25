<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The screen a fresh native registrant lands on (issue #147). Two jobs beyond
 * saying hello:
 *
 * - tell them a verification email is on its way, and to which address;
 * - hand them the sign-in-link URL. Through window A /login still redirects to
 *   Auth0, which has no identity for a native account and which we cannot brand
 *   or link from - so if they get signed out before Stage B, this is the one
 *   thing that gets them back in (implementation-plan §2c, support playbook 4).
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RegistrationWelcomeController extends AbstractController
{
    public function __construct(
        private readonly bool $nativeLoginEnabled,
    ) {
    }

    #[Route(
        path: '/welcome',
        name: 'registration_welcome',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(#[CurrentUser] UserInterface $user): Response
    {
        // Only ever reached right after a native registration. A window-A Auth0
        // session typing the URL in has no account to describe - send it home
        // rather than blow up on the type.
        if (!$user instanceof UserAccount) {
            return $this->redirectToRoute('my_profile');
        }

        $userAccount = $user;

        return $this->render('registration_welcome.html.twig', [
            'email' => $userAccount->email,
            'email_verified' => $userAccount->emailVerifiedAt !== null,
            // Once login is native the rescue is no longer special - the login page
            // itself offers the sign-in link - so the warning retires with window A
            'show_sign_in_link_rescue' => $this->nativeLoginEnabled === false,
        ]);
    }
}
