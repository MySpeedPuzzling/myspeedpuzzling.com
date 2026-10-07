<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The organiser's registration actions on the participants page and the check-in page (D17): each one a Live action
 * of its own, the maintainer re-checked on every request, a participant id of another event never reached.
 */
final class CompetitionRegistrationLiveActionsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string NATIONALS = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testParticipantsPageOfAnEventWithoutManagedRegistrationShowsNothingOfIt(): void
    {
        $component = $this->participantsComponent(CompetitionFixture::COMPETITION_WJPC_2024, managed: false);

        $html = $component->render()->toString();
        self::assertStringNotContainsString('data-registration-counters', $html);
        self::assertStringNotContainsString('data-registration-status', $html);
        self::assertStringNotContainsString('data-model="statusFilter"', $html);
    }

    public function testManagedEventShowsCountersAndOffersTheFirstInLine(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $this->join(PlayerFixture::PLAYER_WITH_FAVORITES);
        // A spot frees up
        $this->messageBus()->dispatch(new ChangeCompetitionRegistrationSettings(self::NATIONALS, true, 2, null, null, 'Europe/Prague', null, null));

        $component = $this->participantsComponent(self::NATIONALS, managed: true, capacity: 2);
        $html = $component->render()->toString();
        self::assertStringContainsString('data-registration-counters', $html);
        self::assertStringContainsString('data-registration-promote-hint', $html);
        self::assertSame(2, substr_count($html, 'data-registration-status="waitlisted"'));

        // The first in line is the one who registered first
        $first = $this->participantIdOf(PlayerFixture::PLAYER_ADMIN);
        self::assertStringContainsString('data-live-participant-id-param="' . $first . '"', $this->promoteHint($html));

        $component->call('promoteFromWaitlist', ['participantId' => $first]);
        self::assertSame('reserved', $this->statusOf($first));
    }

    public function testMarkPaidAndOrganizerNoteOnAManagedEvent(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $participantId = $this->participantIdOf(PlayerFixture::PLAYER_REGULAR);
        $component = $this->participantsComponent(self::NATIONALS, managed: true, capacity: 5);

        $component->call('markPaid', ['participantId' => $participantId]);
        self::assertSame('paid', $this->statusOf($participantId));

        $component->call('startEdit', ['participantId' => $participantId]);
        $component->set('editOrganizerNote', 'Paid cash at the door');
        $component->call('saveEdit');
        self::assertSame('Paid cash at the door', $this->noteOf($participantId));

        // Too long a note is refused, nothing written
        $component->call('startEdit', ['participantId' => $participantId]);
        $component->set('editOrganizerNote', str_repeat('x', 256));
        $component->call('saveEdit');
        self::assertSame('Paid cash at the door', $this->noteOf($participantId));
    }

    public function testEditOnAnEventWithoutManagedRegistrationKeepsTheNote(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_participant SET organizer_note = 'From the managed days' WHERE id = :id",
            ['id' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED],
        );
        $component = $this->participantsComponent(CompetitionFixture::COMPETITION_WJPC_2024, managed: false);

        $component->call('startEdit', ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
        $component->set('editOrganizerNote', 'Sneaked in');
        $component->call('saveEdit');

        self::assertSame('From the managed days', $this->noteOf(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideParticipantsActions(): iterable
    {
        yield 'mark paid' => ['markPaid'];
        yield 'promote and mark paid' => ['promoteAndMarkPaid'];
        yield 'unmark paid' => ['unmarkPaid'];
        yield 'promote' => ['promoteFromWaitlist'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideParticipantsActions')]
    public function testParticipantsActionNeverReachesAnotherEventsParticipant(string $action): void
    {
        $this->manage(capacity: 5);
        $component = $this->participantsComponent(self::NATIONALS, managed: true, capacity: 5);

        try {
            $component->call($action, ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
            self::fail('A participant of another event must not be found');
        } catch (NotFoundHttpException) {
        }

        self::assertNull($this->statusOf(CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCheckInActions(): iterable
    {
        yield 'check in' => ['checkIn'];
        yield 'undo' => ['undoCheckIn'];
        yield 'mark paid' => ['markPaid'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('provideCheckInActions')]
    public function testCheckInActionNeverReachesAnotherEventsParticipant(string $action): void
    {
        $this->manage(capacity: 5);
        $component = $this->checkInComponent(PlayerFixture::PLAYER_ADMIN);

        try {
            $component->call($action, ['participantId' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);
            self::fail('A participant of another event must not be found');
        } catch (NotFoundHttpException) {
        }

        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT checked_in_at FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED],
        ));
    }

    public function testCheckInChecksPeopleInAndLeavesTheWaitlistOut(): void
    {
        $this->manage(capacity: 1);
        $this->join(PlayerFixture::PLAYER_REGULAR);
        $this->join(PlayerFixture::PLAYER_ADMIN);
        $reserved = $this->participantIdOf(PlayerFixture::PLAYER_REGULAR);
        $waitlisted = $this->participantIdOf(PlayerFixture::PLAYER_ADMIN);

        $component = $this->checkInComponent(PlayerFixture::PLAYER_ADMIN);
        $html = $component->render()->toString();
        self::assertStringContainsString('check-in-' . $reserved, $html);
        self::assertStringNotContainsString('check-in-' . $waitlisted, $html);

        $component->call('checkIn', ['participantId' => $reserved]);
        self::assertNotNull(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT checked_in_at FROM competition_participant WHERE id = :id',
            ['id' => $reserved],
        ));
    }

    public function testCheckInIsForTheEventsMaintainersOnly(): void
    {
        $this->manage(capacity: 5);
        $this->join(PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->expectException(AccessDeniedHttpException::class);

        $component = $this->checkInComponent(PlayerFixture::PLAYER_REGULAR);
        $component->call('checkIn', ['participantId' => $this->participantIdOf(PlayerFixture::PLAYER_WITH_FAVORITES)]);
    }

    private function participantsComponent(string $competitionId, bool $managed, null|int $capacity = null): TestLiveComponent
    {
        $client = $this->client;
        TestingLogin::asPlayer($client, PlayerFixture::PLAYER_ADMIN);

        $component = $this->createLiveComponent('ManageCompetitionParticipants', [
            'competitionId' => $competitionId,
            'registrationManaged' => $managed,
            'capacity' => $capacity,
        ], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    private function checkInComponent(string $playerId): TestLiveComponent
    {
        $client = $this->client;
        TestingLogin::asPlayer($client, $playerId);

        $component = $this->createLiveComponent('CompetitionCheckIn', ['competitionId' => self::NATIONALS], $client);
        $component->setRouteLocale('en');

        return $component;
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

    private function promoteHint(string $html): string
    {
        $start = strpos($html, 'data-registration-promote-hint');
        self::assertNotFalse($start);
        $end = strpos($html, '</div>', $start);
        self::assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }

    private function participantIdOf(string $playerId): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => self::NATIONALS, 'pid' => $playerId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function statusOf(string $participantId): null|string
    {
        /** @var false|null|string $status */
        $status = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT registration_status FROM competition_participant WHERE id = :id',
            ['id' => $participantId],
        );

        return $status === false ? null : $status;
    }

    private function noteOf(string $participantId): null|string
    {
        /** @var false|null|string $note */
        $note = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT organizer_note FROM competition_participant WHERE id = :id',
            ['id' => $participantId],
        );

        return $note === false ? null : $note;
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }
}
