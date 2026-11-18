<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A scanned name tag with competitor pages off (participant-linking.md §6.4): a signed-in scanner who enters no results
 * at the event and is not going, scanning a person nobody is linked to, lands on the event page with
 * `?participant=<id>#taking-part` - its "Is this you?" card for that person. Everybody else lands where they did
 * (LiveResultsScanRedirectMatrixTest: its puzzler is going, so its rows stay as they were).
 *
 * Results Cup: Ivan is nobody's, Anna is PLAYER_ADMIN's, Hugo is PLAYER_REGULAR's.
 */
final class ScanClaimLandingTest extends WebTestCase
{
    private const string EVENT_PAGE = '/en/events/results-cup';

    public function testAScannerWhoIsNotGoingGetsTheCardOfTheUnlinkedPerson(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR is not going any more
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET player_id = NULL, connected_at = NULL, connected_via = NULL WHERE id = :id',
            ['id' => F::PARTICIPANT_HUGO],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/live/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_ANNA);
        self::assertResponseRedirects(self::EVENT_PAGE, 302, 'a linked person: the event page as before');

        $browser->request('GET', '/en/live/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertResponseRedirects(self::EVENT_PAGE . '?participant=' . F::PARTICIPANT_IVAN . '#taking-part', 302);

        $page = $browser->request('GET', self::EVENT_PAGE . '?participant=' . F::PARTICIPANT_IVAN);
        self::assertResponseIsSuccessful();
        $card = $page->filter('[data-participant-claim-card="' . F::PARTICIPANT_IVAN . '"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Ivan Last', $card->text());
    }

    public function testAScannerWhoIsGoingOrAGuestLandsAsBefore(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/live/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertResponseRedirects(self::EVENT_PAGE, 302, 'a guest');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/live/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertResponseRedirects(self::EVENT_PAGE, 302, 'going as Hugo');
    }
}
