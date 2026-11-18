<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * "This is me" on a competitor page (participant-linking.md §6.4, R9): a signed-in viewer who is not on the page's person
 * or line-up gets it for each person nobody is linked to; the POST is ClaimParticipant - the handler decides (linked,
 * a request, a dispute, the switch from the viewer's own row). Guests get nothing, the JSON stays as it was.
 *
 * Results Cup: Anna is linked to PLAYER_ADMIN, Hugo to PLAYER_REGULAR, Ivan / Cara / Dan to nobody.
 * PLAYER_WITH_FAVORITES is not at the event.
 */
final class CompetitorPageThisIsMeTest extends WebTestCase
{
    private const string VIEWER = PlayerFixture::PLAYER_WITH_FAVORITES;

    public function testAnUnlinkedPersonOffersThisIsMeToSignedInViewersOnly(): void
    {
        $browser = $this->browser();
        $ivanPage = '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN;

        $page = $browser->request('GET', $ivanPage);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-competitor-claim]'), 'a guest');

        TestingLogin::asPlayer($browser, self::VIEWER);
        $page = $browser->request('GET', $ivanPage);
        self::assertCount(1, $page->filter('[data-competitor-claim-person="' . F::PARTICIPANT_IVAN . '"]'));

        // A linked person: nothing to claim; the state JSON never carries it
        $page = $browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_ANNA);
        self::assertCount(0, $page->filter('[data-competitor-claim]'));
        $browser->request('GET', '/competitor-state/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN);
        self::assertStringNotContainsString('claimable', (string) $browser->getResponse()->getContent());

        // A line-up: each person nobody is linked to
        $corners = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_CARA, F::PARTICIPANT_DAN]);
        $page = $browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/g/' . $corners);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $page->filter('[data-competitor-claim-person]'));

        // A table is nobody's
        $page = $browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/t/1');
        self::assertCount(0, $page->filter('[data-competitor-claim]'));
    }

    public function testTheViewerOnTheTagGetsNoButton(): void
    {
        $browser = $this->browser();
        $sharks = CompetitionLineupId::of(F::COMPETITION_RESULTS_CUP, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);
        // Anna's player on her own pair's page - Ben is unlinked, but this is the viewer's tag
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/g/' . $sharks);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-competitor-claim]'));
    }

    public function testOpenPolicyLinksAtOnceAndReturnsToThePage(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, self::VIEWER);
        $ivanPage = '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN;

        $page = $browser->request('GET', $ivanPage);
        $browser->submit($page->filter('[data-competitor-claim-person] form')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects($ivanPage);
        self::assertSame(['success'], array_keys($this->flashes($browser)));
        self::assertSame(self::VIEWER, $this->linkOf(F::PARTICIPANT_IVAN));

        // Now their own page: no button, the "your page" badge
        $page = $browser->followRedirect();
        self::assertCount(0, $page->filter('[data-competitor-claim]'));
        self::assertCount(1, $page->filter('[data-competitor-own]'));
    }

    public function testApprovalPolicyAsksTheOrganiser(): void
    {
        $browser = $this->browser();
        $this->database()->executeStatement("UPDATE competition SET participant_claims = 'approval' WHERE id = :id", ['id' => F::COMPETITION_RESULTS_CUP]);
        TestingLogin::asPlayer($browser, self::VIEWER);

        $this->claim($browser, F::PARTICIPANT_IVAN);

        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->linkOf(F::PARTICIPANT_IVAN));
        self::assertSame('pending', $this->database()->fetchOne(
            'SELECT status FROM participant_link_request WHERE participant_id = :participant AND player_id = :player',
            ['participant' => F::PARTICIPANT_IVAN, 'player' => self::VIEWER],
        ));
    }

    public function testALinkedPersonIsADispute(): void
    {
        $browser = $this->browser();
        TestingLogin::asPlayer($browser, self::VIEWER);

        $this->claim($browser, F::PARTICIPANT_ANNA);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(['info'], array_keys($this->flashes($browser)));
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $this->linkOf(F::PARTICIPANT_ANNA));
        self::assertSame(1, $this->database()->fetchOne(
            "SELECT COUNT(*) FROM participant_link_request WHERE participant_id = :participant AND player_id = :player AND status = 'pending'",
            ['participant' => F::PARTICIPANT_ANNA, 'player' => self::VIEWER],
        ));
    }

    public function testAnotherParticipantOfTheEventSwitches(): void
    {
        $browser = $this->browser();
        // PLAYER_REGULAR is Hugo here - the claim of Ivan lets go of Hugo (the handler decides, open policy)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->claim($browser, F::PARTICIPANT_IVAN);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->linkOf(F::PARTICIPANT_IVAN));
        self::assertNull($this->linkOf(F::PARTICIPANT_HUGO));
    }

    public function testAGuestOrAnExpiredFormClaimsNothing(): void
    {
        $browser = $this->browser();

        $browser->request('POST', '/en/claim-participant/' . F::COMPETITION_RESULTS_CUP . '/' . F::PARTICIPANT_IVAN, ['_token' => 'x']);
        self::assertFalse($browser->getResponse()->isSuccessful());
        self::assertNull($this->linkOf(F::PARTICIPANT_IVAN));

        TestingLogin::asPlayer($browser, self::VIEWER);
        $browser->request('POST', '/en/claim-participant/' . F::COMPETITION_RESULTS_CUP . '/' . F::PARTICIPANT_IVAN, ['_token' => 'expired']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(['warning'], array_keys($this->flashes($browser)));
        self::assertNull($this->linkOf(F::PARTICIPANT_IVAN));
    }

    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        $this->database()->executeStatement('UPDATE competition SET competitor_pages = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);

        return $browser;
    }

    /**
     * The page's own form for the participant - its CSRF token and the way back
     */
    private function claim(KernelBrowser $browser, string $participantId): void
    {
        $page = $browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . $participantId);
        self::assertResponseIsSuccessful();
        $form = $page->filter('[data-competitor-claim-person="' . $participantId . '"] form');

        if ($form->count() === 0) {
            // A linked person has no button - the same POST with the event's token from Ivan's page, as a stale page
            // would send it (the token is per event)
            $ivan = $browser->request('GET', '/en/competitor/' . F::COMPETITION_RESULTS_CUP . '/p/' . F::PARTICIPANT_IVAN);
            $token = (string) $ivan->filter('[data-competitor-claim-person] input[name="_token"]')->attr('value');
            $browser->request('POST', '/en/claim-participant/' . F::COMPETITION_RESULTS_CUP . '/' . $participantId, ['_token' => $token]);

            return;
        }

        $browser->submit($form->form());
    }

    /**
     * @return array<mixed>
     */
    private function flashes(KernelBrowser $browser): array
    {
        $session = $browser->getRequest()->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);

        return $session->getFlashBag()->peekAll();
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function linkOf(string $participantId): null|string
    {
        $playerId = $this->database()->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($playerId) ? $playerId : null;
    }
}
