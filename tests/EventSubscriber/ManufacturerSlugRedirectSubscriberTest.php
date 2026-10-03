<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use SpeedPuzzling\Web\Message\MergeManufacturers;
use SpeedPuzzling\Web\Tests\CatalogueTestData;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ManufacturerSlugRedirectSubscriberTest extends WebTestCase
{
    use CatalogueTestData;

    protected function tearDown(): void
    {
        // The merge is rolled back by DAMA - do not leave its stats cached
        if (self::$booted) {
            self::clearCatalogueStatsCache(self::getContainer());
        }

        parent::tearDown();
    }

    public function testAMergedBrandsPagesAnswer301ToTheSurvivor(): void
    {
        $browser = $this->browserAfterMergingTreflIntoRavensburger();

        $browser->request('GET', '/en/puzzle/brand/trefl');
        self::assertResponseRedirects('/en/puzzle/brand/ravensburger', 301);

        $browser->request('GET', '/puzzle/znacka/trefl');
        self::assertResponseRedirects('/puzzle/znacka/ravensburger', 301);

        $browser->request('GET', '/en/puzzle/brand/trefl/500-pieces?utm_source=x');
        self::assertResponseRedirects('/en/puzzle/brand/ravensburger/500-pieces?utm_source=x', 301);

        $browser->request('GET', '/en/puzzle/brand/trefl/hardest');
        self::assertResponseRedirects('/en/puzzle/brand/ravensburger/hardest', 301);
    }

    public function testAnUnknownSlugIsStillNotFound(): void
    {
        $browser = $this->browserAfterMergingTreflIntoRavensburger();

        $browser->request('GET', '/en/puzzle/brand/does-not-exist');
        self::assertResponseStatusCodeSame(404);
    }

    private function browserAfterMergingTreflIntoRavensburger(): KernelBrowser
    {
        $browser = self::createClient();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MergeManufacturers(
            survivorManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            mergedManufacturerIds: [ManufacturerFixture::MANUFACTURER_TREFL],
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            decisionSource: MergeDecisionSource::InternalApi,
        ));

        return $browser;
    }
}
