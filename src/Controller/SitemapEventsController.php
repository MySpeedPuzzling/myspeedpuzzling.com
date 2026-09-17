<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetCompetitionSlugsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapEventsController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetCompetitionSlugsForSitemap $getCompetitionSlugsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap-events.xml', name: 'sitemap_events')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach ($this->getCompetitionSlugsForSitemap->standaloneEventSlugs() as $slug) {
            array_push($entries, ...$this->localizedEntries('event_detail', [
                'slug' => $slug,
            ]));
        }

        foreach ($this->getCompetitionSlugsForSitemap->seriesSlugs() as $slug) {
            array_push($entries, ...$this->localizedEntries('competition_series_detail', [
                'slug' => $slug,
            ]));
        }

        foreach ($this->getCompetitionSlugsForSitemap->editionSlugPairs() as $edition) {
            array_push($entries, ...$this->localizedEntries('edition_detail', [
                'seriesSlug' => $edition['series_slug'],
                'editionSlug' => $edition['edition_slug'],
            ]));
        }

        foreach ($this->getCompetitionSlugsForSitemap->roundResultSlugs() as $round) {
            if ($round['series_slug'] === null) {
                array_push($entries, ...$this->localizedEntries('event_round_results', [
                    'slug' => $round['event_slug'],
                    'roundSlug' => $round['round_slug'],
                ]));

                continue;
            }

            array_push($entries, ...$this->localizedEntries('edition_round_results', [
                'seriesSlug' => $round['series_slug'],
                'editionSlug' => $round['event_slug'],
                'roundSlug' => $round['round_slug'],
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
