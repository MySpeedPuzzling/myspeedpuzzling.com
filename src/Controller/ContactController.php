<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ContactController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/kontakt',
            'en' => '/en/contact',
            'es' => '/es/contacto',
            'ja' => '/ja/お問い合わせ',
        ],
        name: 'contact',
    )]
    public function __invoke(Request $request): Response
    {
        return $this->render('contact.html.twig');
    }
}
