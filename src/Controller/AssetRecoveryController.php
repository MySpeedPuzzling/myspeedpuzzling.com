<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manual escape hatch for a browser whose cached /build assets are poisoned.
 *
 * /build is served immutable for a year, so a bad copy in the browser's HTTP
 * cache outlives a CacheStorage purge, a reload and a new deploy of unrelated
 * files. The inline self-heal in base.html.twig recovers automatically, but it
 * needs JavaScript from that page to run and an unusable stylesheet to be
 * detectable; this page is the fallback we can hand to anyone who still reports
 * a broken layout or wrong icons.
 *
 * The rendered page must not reference a single /build asset — it has to work
 * on exactly the browsers where those assets are the problem — so it does not
 * extend base.html.twig and inlines its own styles.
 */
final class AssetRecoveryController extends AbstractController
{
    #[Route(path: '/-/reset', name: 'asset_recovery', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('asset-recovery.html.twig');
    }
}
