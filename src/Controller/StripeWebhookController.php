<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/scan-puzzli/{code}',
            'en' => '/en/scan-puzzle/{code}',
        ],
        name: 'stripe_webhook',
    )]
    public function __invoke(null|string $code): Response
    {
    }
}
