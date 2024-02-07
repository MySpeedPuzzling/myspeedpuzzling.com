<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetPlatformChanges;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ChangelogController extends AbstractController
{
    public function __construct(
        readonly private GetPlatformChanges $getPlatformChanges,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/co-je-noveho',
            'en' => '/en/changelog',
        ],
        name: 'changelog',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('changelog.html.twig', [
            'daily_changes' => $this->getPlatformChanges->all(),
        ]);
    }
}
