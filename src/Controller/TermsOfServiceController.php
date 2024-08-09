<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TermsOfServiceController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/terms-of-service',
            'en' => '/en/terms-of-service',
        ],
        name: 'terms_of_service',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('terms-of-service.html.twig');
    }
}
