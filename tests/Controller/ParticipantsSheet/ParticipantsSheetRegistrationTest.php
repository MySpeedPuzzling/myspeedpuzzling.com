<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ParticipantsSheet;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Query\GetParticipantsSheetVersion;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The managed registration actions of the participants spreadsheet (contract §4.3, O5): the existing messages with
 * their rules and e-mails, a participant of this event only, the official results API rules.
 */
final class ParticipantsSheetRegistrationTest extends WebTestCase
{
    private const string NATIONALS = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;
    private const string ENDPOINT = '/en/participants-sheet-api/' . self::NATIONALS . '/registration';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testMarkPaidConfirmsThePaymentByEmailOnceAndAnswersTheNewRow(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $participantId = $this->participantIdOf(PlayerFixture::PLAYER_REGULAR);
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $participantId, 'action' => 'markPaid']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $answer = $this->json();
        self::assertTrue($answer['ok']);
        self::assertSame(self::getContainer()->get(GetParticipantsSheetVersion::class)->ofCompetition(self::NATIONALS), $answer['version']);
        self::assertIsArray($answer['person']);
        self::assertSame($participantId, $answer['person']['id']);
        self::assertIsArray($answer['person']['registration']);
        self::assertSame('paid', $answer['person']['registration']['status']);
        self::assertIsString($answer['person']['registration']['paidAt']);
        self::assertSame('paid', $this->statusOf($participantId));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_EMAIL, $email->getTo()[0]->getAddress());

        // Paid twice = no change, no second e-mail
        $this->post(['participant' => $participantId, 'action' => 'markPaid']);
        self::assertResponseIsSuccessful();
        self::assertQueuedEmailCount(0);
    }

    public function testTakingBackPaidOnlyTakesBackAPayment(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $participantId = $this->participantIdOf(PlayerFixture::PLAYER_REGULAR);
        $this->messageBus()->dispatch(new MarkParticipantPaid(self::NATIONALS, $participantId));
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $participantId, 'action' => 'unmarkPaid']);

        self::assertResponseIsSuccessful();
        self::assertSame('reserved', $this->statusOf($participantId));
        self::assertQueuedEmailCount(0);
    }

    public function testGivingTheFirstInLineASpotTellsThemByEmail(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $waitlisted = $this->participantIdOf(PlayerFixture::PLAYER_ADMIN);
        self::assertSame('waitlisted', $this->statusOf($waitlisted));
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $waitlisted, 'action' => 'promote']);

        self::assertResponseIsSuccessful();
        self::assertSame('reserved', $this->statusOf($waitlisted));
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(PlayerFixture::PLAYER_ADMIN_EMAIL, $email->getTo()[0]->getAddress());
    }

    public function testAWaitlistedPersonIsMarkedPaidOnlyTogetherWithASpot(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $waitlisted = $this->participantIdOf(PlayerFixture::PLAYER_ADMIN);
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $waitlisted, 'action' => 'markPaid']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'waitlisted_paid', 'message' => 'This person is on the waitlist. Give them a spot to mark them paid.'], $this->json());
        self::assertSame('waitlisted', $this->statusOf($waitlisted));

        $this->post(['participant' => $waitlisted, 'action' => 'checkIn']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('waitlisted_check_in', $this->json()['error']);

        $this->post(['participant' => $waitlisted, 'action' => 'promoteAndMarkPaid']);
        self::assertResponseIsSuccessful();
        self::assertSame('paid', $this->statusOf($waitlisted));
    }

    public function testCheckInAndUndo(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $participantId = $this->participantIdOf(PlayerFixture::PLAYER_REGULAR);
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $participantId, 'action' => 'checkIn']);
        self::assertResponseIsSuccessful();
        $person = $this->json()['person'];
        self::assertIsArray($person);
        self::assertIsArray($person['registration']);
        self::assertIsString($person['registration']['checkedInAt']);
        self::assertNotNull($this->checkedInAt($participantId));

        $this->post(['participant' => $participantId, 'action' => 'undoCheckIn']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->checkedInAt($participantId));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideActions(): iterable
    {
        foreach (['markPaid', 'unmarkPaid', 'promote', 'promoteAndMarkPaid', 'checkIn', 'undoCheckIn'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('provideActions')]
    public function testAnotherEventsParticipantIsNeverReached(string $action): void
    {
        $this->manage(capacity: 5);
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED, 'action' => $action]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('participant_not_found', $this->json()['error']);
        self::assertNull($this->statusOf(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        self::assertNull($this->checkedInAt(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        self::assertQueuedEmailCount(0);
    }

    public function testAnEventWithoutManagedRegistrationIsAConflict(): void
    {
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $this->participantIdOf(PlayerFixture::PLAYER_REGULAR), 'action' => 'markPaid']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'registration_not_managed', 'message' => 'This event does not manage registration on MySpeedPuzzling (any more).'], $this->json());
    }

    public function testARemovedPersonIsRestoredFirst(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $participantId = $this->participantIdOf(PlayerFixture::PLAYER_REGULAR);
        $this->messageBus()->dispatch(new LeaveCompetition(self::NATIONALS, PlayerFixture::PLAYER_REGULAR));
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        $this->post(['participant' => $participantId, 'action' => 'checkIn']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('participant_removed', $this->json()['error']);
    }

    public function testAnUnreadableActionIsABadRequest(): void
    {
        $this->manage(capacity: 5);
        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        foreach ([['participant' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED, 'action' => 'refund'], ['action' => 'markPaid'], ['participant' => 12, 'action' => 'markPaid']] as $body) {
            $this->post($body);

            self::assertResponseStatusCodeSame(400);
            self::assertSame('invalid_request', $this->json()['error']);
        }
    }

    public function testTheOfficialResultsApiRulesApply(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $body = ['participant' => $this->participantIdOf(PlayerFixture::PLAYER_REGULAR), 'action' => 'markPaid'];

        // Signed out: 401, never the login page
        $this->post($body);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'sign_in_required'], $this->json());

        // Not an organiser of the event
        $this->signInAs(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->post($body);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'forbidden'], $this->json());

        $this->signInAs(PlayerFixture::PLAYER_ADMIN);

        // A form post is no JSON
        $this->browser->request('POST', self::ENDPOINT, $body, server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_X_CSRF_TOKEN' => 'csrf-token']);
        self::assertResponseStatusCodeSame(415);
        self::assertSame(['error' => 'json_required'], $this->json());

        // Another site
        $this->post($body, origin: 'https://evil.example');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], $this->json());

        self::assertSame('reserved', $this->statusOf((string) $body['participant']));
    }

    /**
     * Signs in and makes a first request: the setup above ran in the kernel of that request, so the e-mails of the
     * setup (registrations) are left behind - the next request starts a kernel of its own.
     */
    private function signInAs(string $playerId): void
    {
        TestingLogin::asPlayer($this->browser, $playerId);
        $this->browser->request('GET', '/en/participants-sheet-api/' . self::NATIONALS . '/version');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body, string $origin = 'http://localhost'): void
    {
        $this->browser->request('POST', self::ENDPOINT, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
            // The test browser sends the previous request as the referrer - a real request from another site has its own
            'HTTP_REFERER' => $origin . '/',
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

    private function manage(int $capacity): void
    {
        $this->messageBus()->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: self::NATIONALS,
            registrationManaged: true,
            capacity: $capacity,
            registrationOpensAt: null,
            registrationClosesAt: null,
            timezone: 'Europe/Prague',
            entryFeeText: null,
            paymentInstructions: null,
        ));
    }

    private function join(string $playerId): void
    {
        $this->messageBus()->dispatch(new JoinCompetition(self::NATIONALS, $playerId));
    }

    private function participantIdOf(string $playerId): string
    {
        $id = $this->database()->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid',
            ['cid' => self::NATIONALS, 'pid' => $playerId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function statusOf(string $participantId): null|string
    {
        /** @var false|null|string $status */
        $status = $this->database()->fetchOne('SELECT registration_status FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return $status === false ? null : $status;
    }

    private function checkedInAt(string $participantId): null|string
    {
        /** @var false|null|string $checkedInAt */
        $checkedInAt = $this->database()->fetchOne('SELECT checked_in_at FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return $checkedInAt === false ? null : $checkedInAt;
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
