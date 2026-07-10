<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetFeatureRequestIdsForSitemap;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SitemapFeatureRequestsController extends AbstractController
{
    use SitemapResponseTrait;

    public function __construct(
        readonly private GetFeatureRequestIdsForSitemap $getFeatureRequestIdsForSitemap,
    ) {
    }

    #[Route(path: '/sitemap-feature-requests.xml', name: 'sitemap_feature_requests')]
    public function __invoke(): Response
    {
        $entries = [];

        foreach ($this->getFeatureRequestIdsForSitemap->all() as $featureRequestId) {
            array_push($entries, ...$this->localizedEntries('feature_request_detail', [
                'featureRequestId' => $featureRequestId,
            ]));
        }

        return $this->urlsetResponse($entries);
    }
}
