<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantClaim;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organiser's personal claim links (docs/features/competitions-management/participant-linking.md §7.1, §7.4, §7.5):
 * the participants sheet's JSON endpoint (copy / QR / new link), the claim links CSV, the claim slips print kind, the
 * check-in desk's "Show QR" and the hub card's actions - and on an event with online timing its personal links instead
 * (§5.6). Results Cup (PLAYER_WITH_STRIPE's): the people without a profile get the links; Anna Fast is linked.
 */
final class ParticipantClaimLinksOrganiserTest extends WebTestCase
{
    private const string EVENT = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
    private const string ENDPOINT = '/en/participants-sheet-api/' . self::EVENT . '/claim-link';
    private const string CSV = '/en/claim-links/' . self::EVENT . '.csv';
    private const string SLIPS = '/en/name-tags/' . self::EVENT . '?kind=claims';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheSheetGetsAPersonsLinkAndQrMadeOnDemand(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $person = OfficialResultsFixture::PARTICIPANT_BEN;
        self::assertNull($this->token($person));

        $this->post(['participant' => $person, 'renew' => false]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $answer = $this->json();
        $token = $this->token($person);
        self::assertNotNull($token);
        self::assertTrue(OnlineToken::looksValid($token));
        self::assertSame($person, $answer['participant']);
        self::assertIsString($answer['url']);
        self::assertIsString($answer['qr']);
        self::assertMatchesRegularExpression('~^https?://[^/]+/claim/' . $token . '$~', $answer['url']);
        self::assertStringStartsWith('<svg', $answer['qr']);

        // Asked again: the same link
        $this->post(['participant' => $person]);
        self::assertSame($answer['url'], $this->json()['url']);

        // "New link": another one, the old one is gone
        $this->post(['participant' => $person, 'renew' => true]);
        self::assertResponseIsSuccessful();
        $renewed = $this->json();
        self::assertNotSame($answer['url'], $renewed['url']);
        self::assertNotSame($token, $this->token($person));
        self::assertIsString($renewed['url']);
        self::assertStringEndsWith('/claim/' . $this->token($person), $renewed['url']);
    }

    public function testTheSheetsEndpointRefuses(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Linked to a profile already
        $this->post(['participant' => OfficialResultsFixture::PARTICIPANT_ANNA]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('already_linked', $this->json()['error']);
        self::assertNull($this->token(OfficialResultsFixture::PARTICIPANT_ANNA));

        // Another event's participant
        $this->post(['participant' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('participant_not_found', $this->json()['error']);

        $this->post(['participant' => 42]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // Without the CSRF header
        $this->browser->request('POST', self::ENDPOINT, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'], content: '{"participant": "' . OfficialResultsFixture::PARTICIPANT_BEN . '"}');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->token(OfficialResultsFixture::PARTICIPANT_BEN));

        // Not an organiser of the event
        $this->browser->restart();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post(['participant' => OfficialResultsFixture::PARTICIPANT_BEN]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->token(OfficialResultsFixture::PARTICIPANT_BEN));
    }

    public function testAnEventWithOnlineTimingHandsOutItsPersonalLinks(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);
        $event = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;

        $this->post(['participant' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN], '/en/participants-sheet-api/' . $event . '/claim-link');
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('online_timing', $this->json()['error']);

        $this->browser->request('GET', '/en/claim-links/' . $event . '.csv');
        self::assertResponseRedirects('/en/online-competitions/' . $event . '/links.csv');

        $page = $this->browser->request('GET', '/en/name-tags/' . $event . '?kind=claims');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-claims-online]'));
        self::assertCount(0, $page->filter('[data-claim-slip]'));

        $page = $this->browser->request('GET', '/en/manage-event-toolkit/' . $event);
        $card = $page->filter('[data-toolkit-card="participant-links"]');
        self::assertCount(1, $card->filter('[data-claim-action="personal-links"]'));
        self::assertCount(0, $card->filter('[data-claim-action="slips"]'));
        self::assertNull($this->token(OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN));
    }

    public function testTheCsvListsEverybodyNotLinkedWithTheirLink(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::CSV);

        self::assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr((string) $response->getContent(), 3)), static fn (string $line): bool => $line !== '')),
        );
        self::assertSame(['Name', 'Country', 'External ID', 'Claim link'], $lines[0]);

        $unlinked = $this->unlinked();
        self::assertSame(array_column($unlinked, 'name'), array_column(array_slice($lines, 1), 0));

        foreach (array_slice($lines, 1) as $index => $line) {
            $token = $this->token($unlinked[$index]['id']);
            self::assertNotNull($token, 'every link is made first');
            self::assertMatchesRegularExpression('~^https?://[^/]+/claim/' . $token . '$~', (string) $line[3]);
        }

        self::assertStringNotContainsString('Anna Fast', (string) $response->getContent());

        // Only for the event's organisers
        $this->browser->restart();
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::CSV);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testClaimSlipsAreThePeopleNotLinkedEachWithTheirOwnLink(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $this->browser->request('GET', self::SLIPS);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('claims', $page->filter('[data-tag-kind] option[selected]')->attr('value'));
        // Slips are printed on the A4 sheet of tags only
        self::assertCount(0, $page->filter('[data-tag-layout]'));

        $unlinked = $this->unlinked();
        $slips = $page->filter('[data-claim-slip]');
        self::assertCount(count($unlinked), $slips);
        self::assertSame(array_column($unlinked, 'name'), $slips->filter('.tag-name')->each(static fn (Crawler $name): string => $name->text()));

        $slips->each(function (Crawler $slip, int $index) use ($unlinked): void {
            $token = $this->token($unlinked[$index]['id']);
            self::assertNotNull($token);
            self::assertMatchesRegularExpression('~^https?://[^/]+/claim/' . $token . '$~', (string) $slip->filter('[data-qr-url]')->attr('data-qr-url'));
            self::assertCount(1, $slip->filter('svg'));
            self::assertStringContainsString('Scan to link your MySpeedPuzzling profile. Keep this slip to yourself.', $slip->text());
        });

        // Even in tents asked for - slips stay slips
        $page = $this->browser->request('GET', self::SLIPS . '&layout=tents');
        self::assertCount(0, $page->filter('.tent'));
        self::assertCount(count($unlinked), $page->filter('[data-claim-slip]'));
    }

    public function testTheCheckInDeskShowsTheQrOfThePeopleNotLinked(): void
    {
        $this->database->executeStatement('UPDATE competition SET registration_managed = true WHERE id = :id', ['id' => self::EVENT]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $this->browser->request('GET', '/en/event-check-in/' . self::EVENT);

        self::assertResponseIsSuccessful();
        $desk = $page->filter('[data-controller="claim-qr"]');
        self::assertCount(1, $desk);
        self::assertSame(self::ENDPOINT, $desk->attr('data-claim-qr-url-value'));
        self::assertNotSame('', (string) $desk->attr('data-claim-qr-csrf-token-value'));

        $notLinked = $page->filter('[data-check-in-not-linked]');
        self::assertCount(count($this->unlinked()), $notLinked);
        self::assertStringContainsString('Not linked', $notLinked->first()->text());
        self::assertSame('claim-qr#show', $notLinked->first()->filter('button')->attr('data-action'));
        self::assertContains($notLinked->first()->filter('button')->attr('data-participant-id'), array_column($this->unlinked(), 'id'));
    }

    public function testTheHubCardOffersTheSlipsAndTheCsv(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $this->browser->request('GET', '/en/manage-event-toolkit/' . self::EVENT);

        $card = $page->filter('[data-toolkit-card="participant-links"]');
        self::assertSame(self::SLIPS, $card->filter('[data-claim-action="slips"]')->attr('href'));
        self::assertSame(self::CSV, $card->filter('[data-claim-action="csv"]')->attr('href'));
        self::assertCount(0, $card->filter('[data-claim-action="personal-links"]'));
        // Opening the hub makes no link
        self::assertNull($this->token(OfficialResultsFixture::PARTICIPANT_BEN));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, string $endpoint = self::ENDPOINT): void
    {
        $this->browser->request('POST', $endpoint, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/',
            'HTTP_X_CSRF_TOKEN' => 'csrf-token',
        ], content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function token(string $participantId): null|string
    {
        $token = $this->database->fetchOne('SELECT claim_token FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($token) ? $token : null;
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function unlinked(): array
    {
        /** @var list<array{id: string, name: string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, name FROM competition_participant WHERE competition_id = :id AND deleted_at IS NULL AND player_id IS NULL ORDER BY name, id',
            ['id' => self::EVENT],
        );
        self::assertNotSame([], $rows, 'No canary: nobody without a profile');

        return $rows;
    }
}
