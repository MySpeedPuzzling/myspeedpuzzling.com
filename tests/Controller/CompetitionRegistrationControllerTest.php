<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Managed registration through the pages (docs/features/competitions-management/registration.md): a GET never
 * registers anybody, a registration is a confirmed POST with its CSRF token, and an event without managed
 * registration keeps today's page and costs. Czech Nationals 2024: in person, +60 days, nobody listed.
 */
final class CompetitionRegistrationControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string NATIONALS = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;
    private const string NATIONALS_PAGE = '/en/events/czech-nationals-2024';
    private const string JOIN = '/en/join-event/' . self::NATIONALS;

    public function testJoinPageOfAManagedEventNeverRegistersOnGet(): void
    {
        $browser = self::createClient();
        $this->manage(capacity: 2, entryFee: '10 EUR per person');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::JOIN);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-registration-confirm] form input[name="_token"]');
        self::assertSelectorTextContains('[data-registration-confirm]', '10 EUR per person');
        self::assertSelectorTextContains('[data-registration-confirm]', 'does not process payments');
        self::assertCount(0, $crawler->filter('select[name="participant_id"]'), 'Nobody is listed - no picker');
        self::assertSame(0, $this->rowsOf(PlayerFixture::PLAYER_ADMIN, self::NATIONALS));
    }

    public function testNameFoundOnTheOrganisersListIsOnlyPreselected(): void
    {
        $browser = self::createClient();
        // PLAYER_ADMIN is 'Admin User' from 'cz' - main's flow connects them on the GET (JoinCompetitionControllerTest)
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_participant SET name = 'Admin  user', country = 'cz' WHERE id = :id",
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );
        $this->manage(competitionId: CompetitionFixture::COMPETITION_WJPC_2024);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="participant_id"] option[value="' . CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED . '"][selected]');
        self::assertNull(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT player_id FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        ));
    }

    public function testRegistrationWithoutTheConfirmationTokenChangesNothing(): void
    {
        $browser = self::createClient();
        $this->manage(capacity: 2);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', self::JOIN, ['self_join' => '1']);
        self::assertResponseRedirects(self::JOIN);

        $browser->request('POST', self::JOIN, ['self_join' => '1', '_token' => 'forged']);
        self::assertResponseRedirects(self::JOIN);

        self::assertSame(0, $this->rowsOf(PlayerFixture::PLAYER_ADMIN, self::NATIONALS));
    }

    public function testConfirmedRegistrationIsReservedAndGoesOnLikeIAmGoing(): void
    {
        $browser = self::createClient();
        $this->manage(capacity: 2);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $this->confirm($browser);

        // A member with listings at a marketplace event - on to "What will you bring?" like main's "I'm going"
        self::assertResponseRedirects('/en/events/' . self::NATIONALS . '/what-i-bring?joined=1');
        self::assertSame('reserved', $this->statusOf(PlayerFixture::PLAYER_ADMIN));
        self::assertQueuedEmailCount(1);
    }

    public function testFullEventPutsTheConfirmationOnTheWaitlistWithoutTheMarketplaceStep(): void
    {
        $browser = self::createClient();
        $this->manage(capacity: 1);
        $this->messageBus()->dispatch(new JoinCompetition(self::NATIONALS, PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::JOIN);
        self::assertStringContainsString('waitlist', $crawler->filter('[data-registration-confirm]')->text());

        $this->confirm($browser);

        self::assertResponseRedirects(self::NATIONALS_PAGE);
        self::assertSame('waitlisted', $this->statusOf(PlayerFixture::PLAYER_ADMIN));

        $browser->followRedirect();
        self::assertSelectorTextContains('[data-registration-card]', '#1');
    }

    public function testRegistrationClosedMeanwhileIsRefused(): void
    {
        $browser = self::createClient();
        $this->manage(capacity: 2);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::JOIN);
        $token = $crawler->filter('[data-registration-confirm] input[name="_token"]')->attr('value');

        $this->manage(capacity: 2, closesAt: $this->now()->modify('-1 minute'));
        $browser->request('POST', self::JOIN, ['self_join' => '1', '_token' => $token]);

        self::assertResponseRedirects(self::NATIONALS_PAGE);
        self::assertSame(0, $this->rowsOf(PlayerFixture::PLAYER_ADMIN, self::NATIONALS));
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'closed');
    }

    public function testEventPageOfAManagedEventShowsTheRegistrationCard(): void
    {
        $browser = self::createClient();
        $this->manage(capacity: 2, entryFee: '10 EUR per person', paymentInstructions: 'Bank 123/0100');

        // A visitor: spots, fee, Register - never the payment instructions
        $browser->request('GET', self::NATIONALS_PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-registration-card]', '0 / 2');
        self::assertSelectorTextContains('[data-registration-card]', '10 EUR per person');
        self::assertSelectorExists('[data-registration-card] a[href="' . self::JOIN . '"]');
        self::assertSelectorNotExists('[data-registration-payment-instructions]');

        $this->messageBus()->dispatch(new JoinCompetition(self::NATIONALS, PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::NATIONALS_PAGE);

        self::assertSelectorTextContains('[data-registration-card]', '1 / 2');
        self::assertSelectorTextContains('[data-registration-payment-instructions]', 'Bank 123/0100');
    }

    /**
     * An event that does not manage registration renders main's "I'm going" and costs not one statement more;
     * a managed one costs at most its one statement for a visitor and nothing more for a signed-in player.
     */
    public function testEventWithoutManagedRegistrationLooksAndCostsAsBefore(): void
    {
        $browser = self::createClient();

        [$visitorPlain, $visitorPlainSql] = $this->pageCost($browser);
        self::assertSelectorNotExists('[data-registration-card]');
        self::assertSelectorExists('a[href="' . self::JOIN . '"] .bi-person-plus');
        foreach ($visitorPlainSql as $sql) {
            self::assertStringNotContainsString('spots_taken', $sql);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        [$signedInPlain] = $this->pageCost($browser);

        $this->manage(capacity: 2);

        [$signedInManaged] = $this->pageCost($browser);
        self::assertSelectorExists('[data-registration-card]');
        self::assertSame($signedInPlain, $signedInManaged, 'The registration card replaces the attendance statement');

        $browser->getCookieJar()->clear();
        [$visitorManaged] = $this->pageCost($browser);
        self::assertSame($visitorPlain + 1, $visitorManaged, 'A visitor reads the spots in one statement');
    }

    private function manage(
        string $competitionId = self::NATIONALS,
        null|int $capacity = null,
        null|DateTimeImmutable $closesAt = null,
        null|string $entryFee = null,
        null|string $paymentInstructions = null,
    ): void {
        $this->messageBus()->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: $competitionId,
            registrationManaged: true,
            capacity: $capacity,
            registrationOpensAt: null,
            registrationClosesAt: $closesAt,
            timezone: 'Europe/Prague',
            entryFeeText: $entryFee,
            paymentInstructions: $paymentInstructions,
        ));
    }

    private function confirm(KernelBrowser $browser): void
    {
        $crawler = $browser->request('GET', self::JOIN);
        $form = $crawler->filter('[data-registration-confirm] form')->form();
        $browser->submit($form);
    }

    /**
     * @return array{int, list<string>}
     */
    private function pageCost(KernelBrowser $browser): array
    {
        $browser->request('GET', self::NATIONALS_PAGE);
        $this->startCountingQueries($browser);
        $browser->request('GET', self::NATIONALS_PAGE);
        self::assertResponseIsSuccessful();

        return [$this->queryCount($browser), $this->executedSql($browser)];
    }

    private function rowsOf(string $playerId, string $competitionId): int
    {
        return self::toInt(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => $competitionId, 'pid' => $playerId],
        ));
    }

    private function statusOf(string $playerId): null|string
    {
        /** @var false|null|string $status */
        $status = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT registration_status FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => self::NATIONALS, 'pid' => $playerId],
        );

        return $status === false ? null : $status;
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }

    private function now(): DateTimeImmutable
    {
        return self::getContainer()->get(ClockInterface::class)->now();
    }

    private static function toInt(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
