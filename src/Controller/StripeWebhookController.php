<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\MembershipManagement;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        #[Autowire(param: 'stripeWebhookSecret')]
        readonly private string $stripeWebhookSecret,
        readonly private MembershipManagement $membershipManagement,
    ) {
    }

    #[Route(path: '/webhook/stripe', name: 'stripe_webhook')]
    public function __invoke(Request $request): Response
    {
        /** @var string $signHeader */
        $signHeader = $request->headers->get('Stripe-Signature', '');

        $event = Webhook::constructEvent($request->getContent(), $signHeader, $this->stripeWebhookSecret);

        $this->membershipManagement->handleEvent($event);

        return new Response('ok');
    }
}
