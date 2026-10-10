<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "How progress works" (docs/features/xp-levels/launch-2026-10.md, hub draft A): one grouped list of the five things
 * that grow on MySpeedPuzzling, each card leading to its deep page. Static and public - the same page for everybody,
 * so guests, crawlers and players read one glossary.
 */
final class ProgressionHubController extends AbstractController
{
    #[Route(
        path: [
            'cs' => '/jak-funguje-postup',
            'en' => '/en/how-progress-works',
            'es' => '/es/como-funciona-el-progreso',
            'ja' => '/ja/進歩の仕組み',
            'fr' => '/fr/comment-fonctionne-la-progression',
            'de' => '/de/so-funktioniert-der-fortschritt',
        ],
        name: 'progression_hub',
    )]
    public function __invoke(): Response
    {
        return $this->render('progression_hub.html.twig', [
            'achievements_count' => count(array_filter(
                BadgeType::cases(),
                static fn (BadgeType $type): bool => $type->isTiered(),
            )),
        ]);
    }
}
