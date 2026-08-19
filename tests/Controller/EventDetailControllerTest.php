<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EventDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
    }

    public function testAddMyTimeLinkIsShownToLoggedInPlayerOnStartedEvent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Euro Jigsaw Jam is approved and live today
        $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testAddMyTimeLinkIsHiddenFromAnonymousVisitor(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/events/euro-jigsaw-jam');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_RECURRING_ONLINE));
    }

    public function testAddMyTimeLinkIsHiddenOnUpcomingEvent(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // WJPC 2024 starts in 30 days
        $browser->request('GET', '/en/events/wjpc-2024');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists(self::addTimeLinkSelector(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    private static function addTimeLinkSelector(string $competitionId): string
    {
        return sprintf('a[href$="?competition=%s"]', $competitionId);
    }
}
