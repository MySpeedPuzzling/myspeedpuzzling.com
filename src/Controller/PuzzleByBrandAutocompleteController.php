<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\SearchPuzzle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PuzzleByBrandAutocompleteController extends AbstractController
{
    public function __construct(
        readonly private SearchPuzzle $searchPuzzle,
        readonly private CacheManager $cacheManager,
    ) {
    }

    #[Route(
        path: '/{_locale}/puzzle-by-brand-autocomplete/',
        name: 'puzzle_by_brand_autocomplete',
    )]
    public function __invoke(Request $request): Response
    {
        /** @var string|null $brandSearch */
        $brandSearch = $request->query->get('brand');

        if (!is_string($brandSearch) || Uuid::isValid((string) $brandSearch) === false) {
            return $this->json(['error' => 'Unknown brand id'], 404);
        }

        $results = [];

        foreach ($this->searchPuzzle->byBrandId($brandSearch) as $puzzle) {
            if ($request->getLocale() === 'cs' && $puzzle->puzzleAlternativeName !== null) {
                $puzzleName = <<<HTML
{$puzzle->puzzleAlternativeName} <small>({$puzzle->puzzleName})</small>
HTML;
            } else {
                $puzzleName = $puzzle->puzzleName;
            }

            $img = '';

            if ($puzzle->puzzleImage !== null) {
                $img = <<<HTML
<img alt="Puzzle image" class="img-fluid rounded-2"
    style="max-width: 60px; max-height: 60px;"
    src="{$this->cacheManager->getBrowserPath($puzzle->puzzleImage, 'puzzle_small')}"
/>
HTML;
            }

            $html = <<<HTML
<div class="py-1 d-flex low-line-height">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">
        <div class="mb-1">
            <span class="h6">{$puzzleName}</span>
            <small class="text-muted">{$puzzle->puzzleIdentificationNumber}</small>
        </div>
        <div class="description"><small>{$puzzle->piecesCount} pieces</small></div>
    </div>
</div>
HTML;

            $results[] = [
                'value' => $puzzle->puzzleId,
                'text' => $html,
                'piecesCount' => $puzzle->piecesCount,
            ];
        }

        return new JsonResponse([
            'results' => $results,
        ]);
    }
}
