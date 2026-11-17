<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveResults;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\CompetitorPageUrl;
use SpeedPuzzling\Web\Value\CompetitionTag;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * The contract with package B4 (CompetitorPageUrl): route `competitor_page` with competitionId, kind (p|g|t|e), id.
 */
final class CompetitorPageUrlTest extends TestCase
{
    private const string EVENT = '018d0020-0000-0000-0000-000000000001';

    public function testTheTagsKindLetterAndIdAreTheRouteParameters(): void
    {
        $routes = new RouteCollection();
        $routes->add('competitor_page', new Route('/{_locale}/competitor/{competitionId}/{kind}/{id}'));
        $url = new CompetitorPageUrl(new UrlGenerator($routes, new RequestContext()));

        self::assertSame('/ja/competitor/' . self::EVENT . '/t/7', $url->of(strtoupper(self::EVENT), CompetitionTag::table(7), 'ja'));
        self::assertSame('/en/competitor/' . self::EVENT . '/g/' . self::EVENT, $url->of(self::EVENT, CompetitionTag::lineup(strtoupper(self::EVENT)), 'en'));
    }

    public function testWithoutTheRouteThereIsNoPage(): void
    {
        $url = new CompetitorPageUrl(new UrlGenerator(new RouteCollection(), new RequestContext()));

        self::assertNull($url->of(self::EVENT, CompetitionTag::table(7), 'en'));
    }
}
