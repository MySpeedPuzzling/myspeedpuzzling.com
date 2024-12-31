<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\StripeWebhookHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    public function __construct(
        readonly private StripeWebhookHandler $stripeWebhookHandler,
    ) {
    }

    #[Route(path: '/webhook/stripe', name: 'stripe_webhook')]
    public function __invoke(Request $request): Response
    {
        $signHeader = $request->headers->get('Stripe-Signature');

        if (!is_string($signHeader)) {
            return new Response('nok', 400);
        }

        $this->stripeWebhookHandler->handleWebhook($request->getContent(), $signHeader);

        return new Response('ok');
    }
}
