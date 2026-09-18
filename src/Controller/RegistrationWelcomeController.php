<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The screen a fresh registrant lands on (issue #147): says hello and tells them
 * a verification email is on its way, and to which address.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RegistrationWelcomeController extends AbstractController
{
    #[Route(
        path: '/welcome',
        name: 'registration_welcome',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(#[CurrentUser] UserAccount $userAccount): Response
    {
        return $this->render('registration_welcome.html.twig', [
            'email' => $userAccount->email,
            'email_verified' => $userAccount->emailVerifiedAt !== null,
        ]);
    }
}
