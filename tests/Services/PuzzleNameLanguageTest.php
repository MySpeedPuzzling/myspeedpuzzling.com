<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Services\PuzzleNameLanguage;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The language of the second name line: the page language, on English pages a signed-in player's country language;
 * what crawlers read always follows the page only.
 */
final class PuzzleNameLanguageTest extends KernelTestCase
{
    public function testCzechPage(): void
    {
        $language = $this->onPage('cs');

        self::assertSame('cs', $language->forViewer());
        self::assertSame('cs', $language->forPage());
    }

    public function testEnglishPageForAGuestHasNone(): void
    {
        $language = $this->onPage('en');

        self::assertNull($language->forViewer());
        self::assertNull($language->forPage());
    }

    public function testEnglishPageForACzechPlayerIsCzech(): void
    {
        $language = $this->onPage('en');
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame('cs', $language->forViewer());
        self::assertNull($language->forPage(), 'never what crawlers read');
    }

    public function testEnglishPageForAPlayerFromTheUnitedKingdomHasNone(): void
    {
        $language = $this->onPage('en');
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertNull($language->forViewer());
    }

    public function testAnotherPageLanguageWinsOverThePlayersCountry(): void
    {
        $language = $this->onPage('de');
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame('de', $language->forViewer());
    }

    public function testNoRequestNoLanguage(): void
    {
        self::bootKernel();
        $language = self::getContainer()->get(PuzzleNameLanguage::class);

        self::assertNull($language->forViewer());
        self::assertNull($language->forPage());
    }

    private function onPage(string $locale): PuzzleNameLanguage
    {
        self::bootKernel();
        $request = Request::create('/' . $locale . '/puzzle');
        $request->setLocale($locale);
        self::getContainer()->get(RequestStack::class)->push($request);

        return self::getContainer()->get(PuzzleNameLanguage::class);
    }
}
