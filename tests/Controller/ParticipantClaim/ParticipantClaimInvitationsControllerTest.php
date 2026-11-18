<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantClaim;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\ParticipantLinking\ClaimInvitationsRefusalUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Claim invitations from the organiser's tools and the person's one-click "no more" (docs/features/
 * competitions-management/participant-linking.md §7.1, §7.5, §9.1, R8). Results Cup is PLAYER_WITH_STRIPE's.
 */
final class ParticipantClaimInvitationsControllerTest extends WebTestCase
{
    private const string EVENT = Cup::COMPETITION_RESULTS_CUP;
    private const string ENDPOINT = '/en/participants-sheet-api/' . self::EVENT . '/claim-invitations';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheSheetSendsTheSelectedPeopleTheirInvitations(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');

        $this->post(['participants' => [Cup::PARTICIPANT_BEN, Cup::PARTICIPANT_CARA]]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame(['sent' => 1, 'skipped' => ['no_email' => 1]], $this->json());
        self::assertNotNull($this->database->fetchOne('SELECT claim_invited_at FROM competition_participant WHERE id = :id', ['id' => Cup::PARTICIPANT_BEN]));
    }

    public function testTheSheetsEndpointRefuses(): void
    {
        // Somebody who does not organise the event
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->post(['participants' => [Cup::PARTICIPANT_BEN]]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->post(['participants' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->post(['participants' => [42]]);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        // A draft: the claim link would open nothing for the person
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::EVENT]);
        $this->post(['participants' => [Cup::PARTICIPANT_BEN]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('not_public', $this->json()['error'] ?? null);
    }

    public function testTheHubSendsEverybodyNotLinkedWithAnEmailAfterAConfirmation(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');
        $this->email(Cup::PARTICIPANT_CARA, 'cara@example.test');
        // Linked - not counted
        $this->email(Cup::PARTICIPANT_ANNA, 'anna@example.test');

        $page = $this->browser->request('GET', '/en/manage-event-toolkit/' . self::EVENT);
        $card = $page->filter('[data-claim-invitations]');
        self::assertStringContainsString('(2)', $card->filter('[data-claim-action="invite"]')->text());
        self::assertStringContainsString('without an e-mail', $card->filter('[data-claim-invitations-skipped]')->text());

        $this->browser->submit($card->filter('[data-claim-invitations-confirm]')->form());

        self::assertResponseRedirects('/en/manage-event-toolkit/' . self::EVENT, Response::HTTP_SEE_OTHER);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('body', '2 invitations sent.');
        self::assertCount(2, $this->database->fetchAllAssociative('SELECT id FROM competition_participant WHERE competition_id = :id AND claim_invited_at IS NOT NULL', ['id' => self::EVENT]));
    }

    public function testTheOneClickRefusalIsSignedAndOnlyAPostSaysNo(): void
    {
        $url = self::getContainer()->get(ClaimInvitationsRefusalUrl::class)->forParticipant(Cup::PARTICIPANT_BEN, 'en');

        // Tampered: nothing
        $this->browser->request('GET', str_replace(Cup::PARTICIPANT_BEN, Cup::PARTICIPANT_CARA, $url));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // A mail scanner opening the link changes nothing
        $page = $this->browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('Results Cup', $page->text());
        self::assertCount(1, $page->filter('[data-testid="claim-invitations-refuse-form"]'));
        self::assertNull($this->refusedAt(Cup::PARTICIPANT_BEN));

        // The mail client's RFC 8058 one-click POST: a plain 200
        $this->browser->request('POST', $url, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertNotNull($this->refusedAt(Cup::PARTICIPANT_BEN));

        // The page's button again: 303 back, the page says it is done
        $this->browser->request('POST', $url);
        self::assertResponseRedirects($url, Response::HTTP_SEE_OTHER);
        $page = $this->browser->followRedirect();
        self::assertCount(1, $page->filter('[data-testid="claim-invitations-refused"]'));
    }

    private function email(string $participantId, string $email): void
    {
        $this->database->executeStatement('UPDATE competition_participant SET email = :email WHERE id = :id', ['email' => $email, 'id' => $participantId]);
    }

    private function refusedAt(string $participantId): mixed
    {
        $value = $this->database->fetchOne('SELECT claim_invitations_refused_at FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return $value === false ? null : $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): void
    {
        $this->browser->request('POST', self::ENDPOINT, server: [
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
}
