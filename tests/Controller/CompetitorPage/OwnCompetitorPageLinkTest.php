<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Your competitor page" on the event page (docs/features/competitions-management/spws-demo-ux-leftovers.md §1): a
 * signed-in competitor of an event with competitor pages on gets a link to their own page - in "Taking part" and next
 * to "Competitors" above the rounds. Nobody else, and nothing while the pages are off. "Results Cup": Anna Fast is the
 * admin's participant.
 */
final class OwnCompetitorPageLinkTest extends WebTestCase
{
    private const string PAGE = '/en/events/results-cup';
    private const string OWN_PAGE = '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_ANNA;

    public function testTheCompetitorGetsTheirOwnPage(): void
    {
        $browser = self::createClient();
        $this->turnOn($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $taking = $page->filter('#taking-part [data-own-competitor-page]');
        self::assertCount(1, $taking);
        self::assertSame(self::OWN_PAGE, $taking->attr('href'));
        self::assertStringContainsString('Your competitor page', $taking->text());
        self::assertSame(self::OWN_PAGE, $page->filter('[data-event-standings] [data-own-competitor-page-link]')->attr('href'));

        // The link works
        $browser->request('GET', self::OWN_PAGE);
        $this->assertResponseIsSuccessful();
    }

    public function testNobodyElseAndNothingWhileThePagesAreOff(): void
    {
        $browser = self::createClient();

        // Pages off: not even for the competitor
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $page = $browser->request('GET', self::PAGE);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-own-competitor-page], [data-own-competitor-page-link]'));

        $this->turnOn($browser);

        // A guest and a signed-in player who is not on the list
        foreach ([null, PlayerFixture::PLAYER_WITH_STRIPE] as $viewer) {
            $browser->restart();

            if ($viewer !== null) {
                TestingLogin::asPlayer($browser, $viewer);
            }

            $page = $browser->request('GET', self::PAGE);
            $this->assertResponseIsSuccessful();
            self::assertCount(0, $page->filter('[data-own-competitor-page], [data-own-competitor-page-link]'), (string) $viewer);
            self::assertCount(1, $page->filter('[data-event-competitors-link]'), 'the list is there');
        }
    }

    private function turnOn(KernelBrowser $browser): void
    {
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET competitor_pages = true WHERE id = :id',
            ['id' => F::COMPETITION_RESULTS_CUP],
        );
    }
}
