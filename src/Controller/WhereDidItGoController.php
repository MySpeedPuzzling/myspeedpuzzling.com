<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Navigation\WhereDidItGoBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Where did it go?" (docs/features/navigation README §8, LAUNCH-02): where every item of the old menus, top bar and
 * footer lives now (FormerNavigation, for the viewer by WhereDidItGoBuilder). A public page (noindex, follow) - the
 * links to it are for signed-in players only (implementation plan decision R2); guests get the same rows as every
 * other guest, so their HTML stays shared-cacheable.
 */
final class WhereDidItGoController extends AbstractController
{
    public function __construct(
        readonly private WhereDidItGoBuilder $builder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kam-se-to-presunulo',
            'en' => '/en/where-did-it-go',
            'es' => '/es/donde-esta-ahora',
            'ja' => '/ja/移動したページ',
            'fr' => '/fr/ou-est-passe',
            'de' => '/de/wo-ist-es-jetzt',
        ],
        name: 'where_did_it_go',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        return $this->render('where_did_it_go/index.html.twig', [
            'sections' => $this->builder->sections(),
        ]);
    }
}
