<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CompetitionAutocompleteController extends AbstractController
{
    public function __construct(
        readonly private GetCompetitionEvents $getCompetitionEvents,
        readonly private CacheManager $cacheManager,
    ) {
    }

    #[Route(
        path: '/{_locale}/competition-autocomplete/',
        name: 'competition_autocomplete',
    )]
    public function __invoke(Request $request): Response
    {
        $results = [];

        foreach ($this->getCompetitionEvents->all() as $competition) {
            $img = '';

            if ($competition->logo !== null) {
                $img = <<<HTML
<img alt="Logo image" class="img-fluid rounded-2"
    style="max-width: 60px; max-height: 60px;"
    src="{$this->cacheManager->getBrowserPath($competition->logo, 'puzzle_small')}"
/>
HTML;
            }

            $date = '';

            if ($competition->dateFrom !== null) {
                $date = $competition->dateFrom->format('d.m.Y');

                if ($competition->dateTo !== null) {
                    $date .= ' - ' . $competition->dateTo->format('d.m.Y');
                }
            }

            $location = '';

            if ($competition->locationCountryCode !== null) {
                $location = '<span class="shadow-custom fi fi-' . $competition->locationCountryCode->name . ' me-2"></span>';
            }

            $location .= $competition->location;

            $html = <<<HTML
<div class="py-1 d-flex low-line-height">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">
        <div class="mb-1">
            <span class="h6">{$competition->name}</span>
            <small class="text-muted">{$date}</small>
        </div>
        <div class="description"><small>{$location}</small></div>
    </div>
</div>
HTML;

            $results[] = [
                'value' => $competition->id,
                'text' => $html,
            ];
        }

        return new JsonResponse([
            'results' => $results,
        ]);
    }
}
