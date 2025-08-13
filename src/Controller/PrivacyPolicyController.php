<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PrivacyPolicyController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/zasady-ochrany-osobnich-udaju',
            'en' => '/en/privacy-policy',
        ],
        name: 'privacy_policy',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('privacy-policy.html.twig');
    }
}
