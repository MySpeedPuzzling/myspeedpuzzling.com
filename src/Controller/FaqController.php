<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\SolveTimeDistributionProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FaqController extends AbstractController
{
    public function __construct(
        readonly private SolveTimeDistributionProvider $solveTimeDistributionProvider,
        readonly private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/caste-dotazy',
            'en' => '/en/faq',
            'es' => '/es/preguntas-frecuentes',
            'ja' => '/ja/よくある質問',
            'fr' => '/fr/faq',
            'de' => '/de/haeufige-fragen',
        ],
        name: 'faq',
    )]
    public function __invoke(): Response
    {
        // The 1000-piece answer quotes real community numbers, and it is also
        // striptagged into the schema.org FAQPage markup - so it has to come
        // from the same source as the guides, never from frozen prose.
        $distribution1000 = $this->solveTimeDistributionProvider->forStandardPiecesBuckets()[1000] ?? null;

        return $this->render('faq.html.twig', [
            'median_1000' => $distribution1000 !== null
                ? $this->timeFormatter->compactTime($distribution1000->medianSeconds)
                : null,
            'fast_1000' => $distribution1000 !== null
                ? $this->timeFormatter->compactTime($distribution1000->p25Seconds)
                : null,
        ]);
    }
}
