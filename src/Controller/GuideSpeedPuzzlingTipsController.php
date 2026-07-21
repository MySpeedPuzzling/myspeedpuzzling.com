<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Speed puzzling tips" guide. English-only by design.
 */
final class GuideSpeedPuzzlingTipsController extends AbstractController
{
    public function __construct(
        readonly private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '/en/guides/speed-puzzling-tips', name: 'guide_speed_puzzling_tips', defaults: ['_locale' => 'en'])]
    public function __invoke(): Response
    {
        $distributions = $this->solveTimeDistributionProvider->forStandardPiecesBuckets();

        return $this->render('guides/speed_puzzling_tips.html.twig', [
            'distributions' => $distributions,
            'guide_title' => $this->translator->trans('guides.tips.title', locale: 'en'),
            'guide_description' => $this->translator->trans('guides.tips.meta_description', locale: 'en'),
            'guide_published' => '2026-07-11',
            'guide_modified' => '2026-07-11',
        ]);
    }
}
