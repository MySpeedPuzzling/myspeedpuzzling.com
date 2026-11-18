<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantClaimLinks;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\ClaimInvitationsNotAvailable;
use SpeedPuzzling\Web\Message\EraseParticipantEmails;
use SpeedPuzzling\Web\Message\RefuseParticipantClaimInvitations;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitation;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitations;
use SpeedPuzzling\Web\MessageHandler\SendParticipantClaimInvitationHandler;
use SpeedPuzzling\Web\Query\GetParticipantClaimInvitation;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Results\ClaimInvitationsSent;
use SpeedPuzzling\Web\Results\ParticipantClaimInvitation;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Services\ParticipantLinking\ClaimInvitationsRefusalUrl;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\TestMailerSpy;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Claim invitations by e-mail (docs/features/competitions-management/participant-linking.md §5.5, §9.1, R8, D15): who
 * gets one and who is skipped why, once a day per person, the person's one-click "no more", the e-mail itself (the same
 * for an address with an account and without) and the erasure of the addresses 60 days after the event. Results Cup
 * (PLAYER_WITH_STRIPE's, 10 days ago, public): Anna Fast is linked, the others are not.
 */
final class ParticipantClaimInvitationsTest extends KernelTestCase
{
    private const string EVENT = Cup::COMPETITION_RESULTS_CUP;
    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEverybodyWhoMayGetOneGetsOneAndTheOthersAreCountedByReason(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');
        $this->email(Cup::PARTICIPANT_ANNA, 'anna@example.test');
        $this->email(Cup::PARTICIPANT_DAN, 'dan@example.test');
        $this->database->executeStatement('UPDATE competition_participant SET claim_invitations_refused_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => Cup::PARTICIPANT_DAN]);
        $this->email(Cup::PARTICIPANT_EVA, 'eva@example.test');
        $this->database->executeStatement('UPDATE competition_participant SET claim_invited_at = ' . TestClock::SQL_NOW . " - INTERVAL '2 hours' WHERE id = :id", ['id' => Cup::PARTICIPANT_EVA]);
        $this->email(Cup::PARTICIPANT_FILIP, 'filip@example.test');
        $this->suppress('filip@example.test', 'hard_bounce');
        // Gina and Hugo are linked to profiles - Ivan is not
        $this->email(Cup::PARTICIPANT_IVAN, 'ivan@example.test');
        $this->suppress('ivan@example.test', 'invitation_complaint');

        $result = $this->send([
            Cup::PARTICIPANT_BEN, Cup::PARTICIPANT_ANNA, Cup::PARTICIPANT_CARA, Cup::PARTICIPANT_DAN, Cup::PARTICIPANT_EVA,
            Cup::PARTICIPANT_FILIP, Cup::PARTICIPANT_IVAN, Uuid::uuid7()->toString(),
        ]);

        // Filip's and Ivan's addresses take no e-mail from us: dropped silently, counted as sent - nothing tells the
        // organiser what we know of an address (D10)
        self::assertSame(3, $result->sent);
        self::assertEquals(['linked' => 1, 'no_email' => 1, 'said_no' => 1, 'invited_recently' => 1, 'not_on_list' => 1], $result->skipped);

        // Ben: a claim link made, the invitation recorded and logged, one e-mail queued
        $token = $this->column(Cup::PARTICIPANT_BEN, 'claim_token');
        self::assertIsString($token);
        self::assertTrue(OnlineToken::looksValid($token));
        self::assertSame(TestClock::FROZEN_NOW, $this->column(Cup::PARTICIPANT_BEN, 'claim_invited_at'));
        self::assertSame(
            ['action' => 'invitation_sent', 'player_id' => null, 'player_name' => '', 'by_player_id' => self::ORGANISER],
            $this->database->fetchAssociative('SELECT action, player_id, player_name, by_player_id FROM participant_link_log WHERE participant_id = :id', ['id' => Cup::PARTICIPANT_BEN]),
        );
        self::assertSame([[Cup::PARTICIPANT_BEN, 'ben@example.test']], $this->queued());

        // Nobody else got anything
        self::assertNull($this->column(Cup::PARTICIPANT_CARA, 'claim_token'));
        self::assertNull($this->column(Cup::PARTICIPANT_FILIP, 'claim_invited_at'));
        self::assertNull($this->column(Cup::PARTICIPANT_IVAN, 'claim_invited_at'));
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM participant_link_log WHERE action = 'invitation_sent' AND participant_id IN (:filip, :ivan)", ['filip' => Cup::PARTICIPANT_FILIP, 'ivan' => Cup::PARTICIPANT_IVAN]));
    }

    /**
     * R8 per address over the event: a person removed and added again (a new row) keeps their "no" and their 24 hours;
     * two people sharing an address get one e-mail a day
     */
    public function testRemovingAndAddingAPersonAgainKeepsTheirNoAndTheirTwentyFourHours(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');
        $this->refuse(Cup::PARTICIPANT_BEN);
        $this->database->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => Cup::PARTICIPANT_BEN]);
        $benAgain = $this->participantWithEmail(self::EVENT, 'ben@example.test');

        self::assertSame(['said_no' => 1], $this->send([$benAgain])->skipped);
        self::assertTrue($this->claimInvitation($benAgain)->refused, 'The sender checks it again too');

        $this->email(Cup::PARTICIPANT_CARA, 'cara@example.test');
        self::assertSame(1, $this->send([Cup::PARTICIPANT_CARA])->sent);
        $this->database->executeStatement('UPDATE competition_participant SET deleted_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => Cup::PARTICIPANT_CARA]);
        $caraAgain = $this->participantWithEmail(self::EVENT, 'cara@example.test');
        self::assertSame(['invited_recently' => 1], $this->send([$caraAgain])->skipped);

        // A family sharing an address: one e-mail, the other waits a day
        $this->email(Cup::PARTICIPANT_DAN, 'family@example.test');
        $this->email(Cup::PARTICIPANT_EVA, 'family@example.test');
        $family = $this->send([Cup::PARTICIPANT_DAN, Cup::PARTICIPANT_EVA]);
        self::assertSame(1, $family->sent);
        self::assertSame(['invited_recently' => 1], $family->skipped);
    }

    public function testOncePerPersonInTwentyFourHours(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');

        self::assertSame(1, $this->send([Cup::PARTICIPANT_BEN])->sent);
        $again = $this->send([Cup::PARTICIPANT_BEN]);
        self::assertSame(0, $again->sent);
        self::assertSame(['invited_recently' => 1], $again->skipped);

        $this->database->executeStatement('UPDATE competition_participant SET claim_invited_at = ' . TestClock::SQL_NOW . " - INTERVAL '25 hours' WHERE id = :id", ['id' => Cup::PARTICIPANT_BEN]);
        self::assertSame(1, $this->send([Cup::PARTICIPANT_BEN])->sent);
    }

    public function testNoListMeansEverybodyOnTheList(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');
        $this->email(Cup::PARTICIPANT_CARA, 'cara@example.test');

        $result = $this->messageBus->dispatch(new SendParticipantClaimInvitations(self::EVENT, null, self::ORGANISER))->last(HandledStamp::class)?->getResult();

        self::assertInstanceOf(ClaimInvitationsSent::class, $result);
        self::assertSame(2, $result->sent);
    }

    public function testAnEventThatIsNotPublicSendsNothing(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => self::EVENT]);

        try {
            $this->send([Cup::PARTICIPANT_BEN]);
            self::fail('A draft sent invitations');
        } catch (ClaimInvitationsNotAvailable $exception) {
            self::assertSame(ClaimInvitationsNotAvailable::NOT_PUBLIC, $exception->reason);
        }

        self::assertNull($this->column(Cup::PARTICIPANT_BEN, 'claim_invited_at'));
        self::assertSame([], $this->queued());
    }

    public function testTheEmailIsTheSameForAnAddressWithAnAccountAndWithout(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'nobody@example.test');
        // An English e-mail (the language follows the country the organiser gave)
        $this->database->executeStatement("UPDATE competition_participant SET country = 'us' WHERE id = :id", ['id' => Cup::PARTICIPANT_BEN]);
        $this->send([Cup::PARTICIPANT_BEN]);

        $withoutAccount = $this->sendEmail(Cup::PARTICIPANT_BEN, 'nobody@example.test');
        // The same row, now with an address that has an account
        $this->email(Cup::PARTICIPANT_BEN, PlayerFixture::PLAYER_REGULAR_EMAIL);
        $withAccount = $this->sendEmail(Cup::PARTICIPANT_BEN, PlayerFixture::PLAYER_REGULAR_EMAIL);

        self::assertNotNull($withoutAccount);
        self::assertNotNull($withAccount);
        self::assertSame($withoutAccount->getSubject(), $withAccount->getSubject());
        self::assertSame($withoutAccount->getHtmlBody(), $withAccount->getHtmlBody());
        self::assertSame($withoutAccount->getTextBody(), $withAccount->getTextBody());

        $html = (string) $withAccount->getHtmlBody();
        $token = $this->column(Cup::PARTICIPANT_BEN, 'claim_token');
        self::assertIsString($token);
        self::assertSame('Link your profile to Results Cup', $withAccount->getSubject());
        self::assertStringContainsString('/claim/' . $token, $html);
        self::assertStringContainsString('Ben Steady', $html);
        self::assertStringContainsString('Brno', $html);
        self::assertStringContainsString('Link my profile', $html);
        self::assertStringContainsString("Don't send me more invitations for this event", $html);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR_EMAIL, $html);
        self::assertSame('transactional', $withAccount->getHeaders()->get('X-Transport')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $withAccount->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertStringContainsString('/claim-invitations/no-more/' . Cup::PARTICIPANT_BEN, (string) $withAccount->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame([], $withAccount->getReplyTo());
    }

    public function testTheSenderChecksAgain(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');
        $this->send([Cup::PARTICIPANT_BEN]);

        // The address changed meanwhile
        self::assertNull($this->sendEmail(Cup::PARTICIPANT_BEN, 'old@example.test'));

        // The address takes no e-mail from us by now
        $this->suppress('ben@example.test', 'hard_bounce');
        self::assertNull($this->sendEmail(Cup::PARTICIPANT_BEN, 'ben@example.test'));
        $this->database->executeStatement("DELETE FROM email_suppression WHERE email = 'ben@example.test'");

        // Said no meanwhile
        $this->refuse(Cup::PARTICIPANT_BEN);
        self::assertNull($this->sendEmail(Cup::PARTICIPANT_BEN, 'ben@example.test'));
        $this->database->executeStatement('UPDATE competition_participant SET claim_invitations_refused_at = NULL WHERE id = :id', ['id' => Cup::PARTICIPANT_BEN]);

        // Linked meanwhile
        $this->database->executeStatement('UPDATE competition_participant SET player_id = :player WHERE id = :id', ['player' => PlayerFixture::PLAYER_REGULAR, 'id' => Cup::PARTICIPANT_BEN]);
        self::assertNull($this->sendEmail(Cup::PARTICIPANT_BEN, 'ben@example.test'));
        $this->database->executeStatement('UPDATE competition_participant SET player_id = NULL WHERE id = :id', ['id' => Cup::PARTICIPANT_BEN]);

        // Everything as it was: it goes
        self::assertNotNull($this->sendEmail(Cup::PARTICIPANT_BEN, 'ben@example.test'));
    }

    public function testSayingNoStopsTheInvitationsOfThisEventAndSayingItAgainChangesNothing(): void
    {
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');

        $this->refuse(Cup::PARTICIPANT_BEN);
        $first = $this->column(Cup::PARTICIPANT_BEN, 'claim_invitations_refused_at');
        self::assertSame(TestClock::FROZEN_NOW, $first);
        $this->refuse(Cup::PARTICIPANT_BEN);
        self::assertSame($first, $this->column(Cup::PARTICIPANT_BEN, 'claim_invitations_refused_at'));

        self::assertSame(['said_no' => 1], $this->send([Cup::PARTICIPANT_BEN])->skipped);
    }

    /**
     * D15: 60 days after the end of the event's last day in its zone - Kiritimati (UTC+14) is past it, Pago Pago (UTC-11)
     * not yet; an event 10 days ago and one in the future keep theirs
     */
    public function testTheAddressesAreErasedSixtyDaysAfterTheEventInItsZone(): void
    {
        $early = EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR;
        $late = EventsPageFixture::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO;
        // FROZEN_NOW 2026-10-14 12:00 UTC: 15 August ends 14 August 10:00 UTC in Kiritimati, 15 August 11:00 UTC in Pago Pago
        $this->database->executeStatement("UPDATE competition SET date_from = '2026-08-15 00:00:00', date_to = '2026-08-15 00:00:00', registration_timezone = 'Pacific/Kiritimati' WHERE id = :id", ['id' => $early]);
        $this->database->executeStatement("UPDATE competition SET date_from = '2026-08-15 00:00:00', date_to = '2026-08-15 00:00:00', registration_timezone = 'Pacific/Pago_Pago' WHERE id = :id", ['id' => $late]);
        $erased = $this->participantWithEmail($early, 'early@example.test');
        $kept = $this->participantWithEmail($late, 'late@example.test');
        $this->email(Cup::PARTICIPANT_BEN, 'ben@example.test');

        $count = $this->messageBus->dispatch(new EraseParticipantEmails())->last(HandledStamp::class)?->getResult();

        self::assertSame(1, $count);
        self::assertNull($this->column($erased, 'email'));
        self::assertSame('late@example.test', $this->column($kept, 'email'));
        self::assertSame('ben@example.test', $this->column(Cup::PARTICIPANT_BEN, 'email'));
    }

    /**
     * @param list<string> $participantIds
     */
    private function send(array $participantIds): ClaimInvitationsSent
    {
        $result = $this->messageBus->dispatch(new SendParticipantClaimInvitations(self::EVENT, $participantIds, self::ORGANISER))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ClaimInvitationsSent::class, $result);

        return $result;
    }

    private function refuse(string $participantId): void
    {
        $this->messageBus->dispatch(new RefuseParticipantClaimInvitations(self::EVENT, $participantId));
    }

    private function sendEmail(string $participantId, string $email): null|TemplatedEmail
    {
        $container = self::getContainer();
        $mailer = new TestMailerSpy();
        $handler = new SendParticipantClaimInvitationHandler(
            getParticipantClaimInvitation: $container->get(GetParticipantClaimInvitation::class),
            emailSuppressionRepository: $container->get(EmailSuppressionRepository::class),
            nameTagQrCode: $container->get(NameTagQrCode::class),
            refusalUrl: $container->get(ClaimInvitationsRefusalUrl::class),
            mailer: $mailer,
            translator: $container->get(TranslatorInterface::class),
            clock: $container->get(ClockInterface::class),
        );
        $handler(new SendParticipantClaimInvitation($participantId, $email));

        if ($mailer->sent === []) {
            return null;
        }

        $message = $mailer->sent[0];
        self::assertInstanceOf(TemplatedEmail::class, $message);
        // Read before rendering - the renderer clears it
        self::assertSame('emails/participant_claim_invitation.html.twig', $message->getHtmlTemplate());
        $container->get('twig.mime_body_renderer')->render($message);

        return $message;
    }

    private function claimInvitation(string $participantId): ParticipantClaimInvitation
    {
        $invitation = self::getContainer()->get(GetParticipantClaimInvitation::class)->byId($participantId);
        self::assertNotNull($invitation);

        return $invitation;
    }

    private function email(string $participantId, string $email): void
    {
        $this->database->executeStatement('UPDATE competition_participant SET email = :email WHERE id = :id', ['email' => $email, 'id' => $participantId]);
    }

    private function suppress(string $email, string $source): void
    {
        $this->database->executeStatement(
            'INSERT INTO email_suppression (id, email, suppressed_at, source) VALUES (:id, :email, ' . TestClock::SQL_NOW . ', :source)',
            ['id' => Uuid::uuid7()->toString(), 'email' => $email, 'source' => $source],
        );
    }

    private function participantWithEmail(string $competitionId, string $email): string
    {
        $id = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source, email) VALUES (:id, 'Kim Example', 'us', :competition, 'imported', :email)",
            ['id' => $id, 'competition' => $competitionId, 'email' => $email],
        );

        return $id;
    }

    private function column(string $participantId, string $column): mixed
    {
        $value = $this->database->fetchOne("SELECT {$column} FROM competition_participant WHERE id = :id", ['id' => $participantId]);

        return $value === false ? null : $value;
    }

    /**
     * @return list<array{string, string}>
     */
    private function queued(): array
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $queued = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendParticipantClaimInvitation) {
                $queued[] = [$message->participantId, $message->email];
            }
        }

        return $queued;
    }
}
