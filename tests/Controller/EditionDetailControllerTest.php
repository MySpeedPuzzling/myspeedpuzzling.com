<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditionDetailControllerTest extends WebTestCase
{
    private const string PAST_EDITION_URL = '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026';
    private const string UPCOMING_EDITION_URL = '/en/series/euro-jigsaw-jam-series/ejj-69-may-2026';

    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
    }

    public function testAddMyTimeLinkIsShownToLoggedInPlayerOnPastEdition(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(self::addTimeLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_68));
    }

    public function testAddMyTimeLinkIsHiddenFromAnonymousVisitor(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PAST_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_68));
    }

    public function testAddMyTimeLinkIsHiddenOnUpcomingEdition(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::UPCOMING_EDITION_URL);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    private static function addTimeLinkSelector(string $competitionId): string
    {
        return sprintf('a[href$="?competition=%s"]', $competitionId);
    }
}
