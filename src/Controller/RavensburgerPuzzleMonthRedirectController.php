<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Value\RavensburgerPuzzleMonthEditions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The human-readable URL printed on Ravensburger "Puzzle Month" boxes. It only
 * redirects to the edition's puzzle detail (in the visitor's language, tagged for
 * analytics) - the puzzle itself is a hidden placeholder until the reveal, see
 * docs/ravensburger-puzzle-month.md.
 *
 * Temporary redirect on purpose: browsers cache a 301 forever and the target may
 * still change (e.g. the placeholder gets merged into another puzzle).
 */
final class RavensburgerPuzzleMonthRedirectController extends AbstractController
{
    /** English first: getPreferredLanguage() falls back to the first entry when nothing matches */
    private const array SUPPORTED_LOCALES = ['en', 'cs', 'es', 'ja', 'fr', 'de'];

    #[Route(
        path: '/ravensburger-puzzle-month/{edition}',
        name: 'ravensburger_puzzle_month',
        requirements: ['edition' => '\d+'],
    )]
    public function __invoke(Request $request, string $edition): Response
    {
        $puzzleId = RavensburgerPuzzleMonthEditions::puzzleId((int) $edition);

        if ($puzzleId === null) {
            throw $this->createNotFoundException();
        }

        $preferredLocale = $request->getPreferredLanguage(self::SUPPORTED_LOCALES) ?? 'en';

        return $this->redirectToRoute('puzzle_detail', [
            'puzzleId' => $puzzleId,
            '_locale' => $preferredLocale,
            'utm_source' => 'puzzle_box',
            'utm_campaign' => 'ravensburger-puzzle-month-' . (int) $edition,
        ]);
    }
}
