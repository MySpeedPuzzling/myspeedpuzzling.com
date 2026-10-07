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
 * The organiser's registration actions on the check-in page (D17): each one a Live action of its own, the maintainer
 * re-checked on every request, a participant id of another event never reached. The participants spreadsheet's
 * registration actions: ParticipantsSheetRegistrationTest.
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

    private function participantIdOf(string $playerId): string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => self::NATIONALS, 'pid' => $playerId],
        );
        self::assertIsString($id);

        return $id;
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }
}
