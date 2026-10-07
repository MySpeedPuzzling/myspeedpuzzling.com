<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use SpeedPuzzling\Web\Services\CompetitionDetailUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The two links into the live entry (docs/features/competitions-management/live-results.md): the event link for the
 * referees (always the current round) and the name tag's QR (the participant's round with them opened, for organisers
 * only - everybody else lands on the event page).
 */
final class LiveResultsLinksTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testTheEventLinkOpensTheCurrentRound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/live-results/event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        // Every round of the fixture event was ten days ago: none runs, none started lately, none is next - the last one
        self::assertResponseRedirects('/en/live-results/' . OfficialResultsFixture::ROUND_PAIRS_FINAL . '?auto=1');
    }

    public function testTheEventLinkOfAnEventWithoutRoundsLeadsToTheRounds(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/live-results/event/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertResponseRedirects('/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_UNAPPROVED);
    }

    public function testTheEventLinkIsForOrganisersOnly(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/en/live-results/event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOrganiserScanningANameTagGetsThePersonsCurrentRoundWithThemOpened(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Anna is in Group A, the Pairs (Puzzle Sharks) and the Final - the last of hers
        $this->browser->request('GET', $this->scanUrl(OfficialResultsFixture::PARTICIPANT_ANNA));

        self::assertResponseRedirects('/en/live-results/' . OfficialResultsFixture::ROUND_FINAL . '?entrant=' . OfficialResultsFixture::PARTICIPANT_ANNA);
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

        // Ivan is in Group B only
        $this->browser->request('GET', $this->scanUrl(OfficialResultsFixture::PARTICIPANT_IVAN));

        self::assertResponseRedirects('/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_B . '?entrant=' . OfficialResultsFixture::PARTICIPANT_IVAN);
    }

    public function testEverybodyElseLandsOnTheEventPage(): void
    {
        $eventPage = self::getContainer()->get(CompetitionDetailUrl::class)->of(OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        // Signed out
        $this->browser->request('GET', $this->scanUrl(OfficialResultsFixture::PARTICIPANT_ANNA));
        self::assertResponseRedirects($eventPage);

        // A puzzler - even the one on the name tag
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $this->scanUrl(OfficialResultsFixture::PARTICIPANT_HUGO));
        self::assertResponseRedirects($eventPage);
    }

    public function testANameTagOfAnotherEventOrAnUnknownPersonLeadsToTheEventPage(): void
    {
        $eventPage = self::getContainer()->get(CompetitionDetailUrl::class)->of(OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', $this->scanUrl(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        self::assertResponseRedirects($eventPage);

        $this->browser->request('GET', $this->scanUrl('018d0020-0000-0000-0000-00000000ffff'));
        self::assertResponseRedirects($eventPage);
    }

    public function testANameTagOfAnUnknownEventLeadsToTheEvents(): void
    {
        $this->browser->request('GET', '/en/live/018d0020-0000-0000-0000-00000000ffff/p/' . OfficialResultsFixture::PARTICIPANT_ANNA);

        self::assertResponseRedirects('/en/events');
    }

    private function scanUrl(string $participantId): string
    {
        return '/en/live/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/p/' . $participantId;
    }
}
