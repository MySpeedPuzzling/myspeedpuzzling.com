<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeCheckoutCancelController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/nakup-clenstvi-zrusen',
            'en' => '/en/membership-checkout-cancel',
        ],
        name: 'stripe_checkout_cancel',
    )]
    public function __invoke(null|string $code): Response
    {
        return $this->render('membership-checkout-cancel.html.twig');
    }
}
