<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\EventSubscriber\NativeAuthPageSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The goodbye page after a confirmed account deletion. A plain page of its own
 * (not a flash on the homepage): the browser that confirmed was just signed
 * out, and its session with it, so there is nowhere to hang a flash - and the
 * link may well have been opened somewhere else entirely.
 */
final class AccountDeletedController extends AbstractController
{
    #[Route(
        path: '/account-deleted',
        name: 'account_deleted',
        defaults: [NativeAuthPageSubscriber::ROUTE_DEFAULT => true],
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('account_deleted.html.twig');
    }
}
