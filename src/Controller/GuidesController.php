<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Guides hub index. Guides are English-only by design: a single /en/ URL,
 * no localized route variants (head handling is overridden in the guides
 * base template accordingly).
 */
final class GuidesController extends AbstractController
{
    public function __construct(
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/en/guides', name: 'guides', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        return $this->render('guides/index.html.twig', [
            'guide_title' => $this->translator->trans('guides.index.title', locale: 'en'),
            'guide_description' => $this->translator->trans('guides.index.meta_description', locale: 'en'),
        ]);
    }
}
