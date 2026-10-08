<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\PublishCompetition;
use SpeedPuzzling\Web\Message\PublishCompetitionSeries;
use SpeedPuzzling\Web\Message\PublishOrganization;
use SpeedPuzzling\Web\Message\UnpublishCompetition;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Message\UnpublishOrganization;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/organizations/README.md "Drafts": publishing a draft that still waits for approval submits it (the admin
 * e-mail), unpublishing is refused while people joined, official results exist or solving times are linked.
 */
final class DraftPublishingHandlersTest extends KernelTestCase
{
    private const string ADMIN_EMAIL = 'jan.mikes@myspeedpuzzling.com';

    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testPublishingAnApprovedDraftEventSendsNoEmail(): void
    {
        $this->messageBus->dispatch(new PublishCompetition(OrganizationFixture::COMPETITION_DRAFT_NIGHT));

        self::assertFalse($this->isDraft('competition', OrganizationFixture::COMPETITION_DRAFT_NIGHT));
        self::assertQueuedEmailCount(0);
    }

    public function testPublishingAPendingDraftEventSubmitsItToTheAdmin(): void
    {
        $this->messageBus->dispatch(new PublishCompetition(OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT));

        self::assertFalse($this->isDraft('competition', OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT));
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail('No e-mail'), 'To', self::ADMIN_EMAIL);
    }

    public function testPublishingADraftEditionSendsNoEmail(): void
    {
        // An edition is approved through its series
        $this->messageBus->dispatch(new PublishCompetition(OrganizationFixture::EDITION_LANTERN_DRAFT));

        self::assertFalse($this->isDraft('competition', OrganizationFixture::EDITION_LANTERN_DRAFT));
        self::assertQueuedEmailCount(0);
    }

    public function testPublishingAPublishedEventSubmitsNothingAgain(): void
    {
        $this->messageBus->dispatch(new PublishCompetition(CompetitionFixture::COMPETITION_UNAPPROVED));

        self::assertFalse($this->isDraft('competition', CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertQueuedEmailCount(0);
    }

    public function testPublishingAnApprovedDraftSeriesSendsNoEmailAndKeepsItsEditionsFlags(): void
    {
        $this->connection->executeStatement(
            'UPDATE competition SET is_draft = true WHERE id = :id',
            ['id' => OrganizationFixture::EDITION_QUIET_PINES_1],
        );

        $this->messageBus->dispatch(new PublishCompetitionSeries(OrganizationFixture::SERIES_QUIET_PINES_DRAFT));

        self::assertFalse($this->isDraft('competition_series', OrganizationFixture::SERIES_QUIET_PINES_DRAFT));
        // P8: an edition's own draft flag stays
        self::assertTrue($this->isDraft('competition', OrganizationFixture::EDITION_QUIET_PINES_1));
        self::assertQueuedEmailCount(0);
    }

    public function testPublishingAPendingDraftSeriesSubmitsItToTheAdmin(): void
    {
        $this->connection->executeStatement(
            'UPDATE competition_series SET is_draft = true WHERE id = :id',
            ['id' => OrganizationFixture::SERIES_MAPLE_PENDING],
        );

        $this->messageBus->dispatch(new PublishCompetitionSeries(OrganizationFixture::SERIES_MAPLE_PENDING));

        self::assertFalse($this->isDraft('competition_series', OrganizationFixture::SERIES_MAPLE_PENDING));
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail('No e-mail'), 'To', self::ADMIN_EMAIL);
    }

    public function testPublishingAPendingDraftOrganizationSubmitsItToTheAdmin(): void
    {
        $this->messageBus->dispatch(new PublishOrganization(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));

        self::assertFalse($this->isDraft('organization', OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT));
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage() ?? self::fail('No e-mail'), 'To', self::ADMIN_EMAIL);
    }

    public function testPublishingAnApprovedDraftOrganizationSendsNoEmail(): void
    {
        $this->messageBus->dispatch(new PublishOrganization(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT));

        self::assertFalse($this->isDraft('organization', OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT));
        self::assertQueuedEmailCount(0);
    }

    public function testAnOrganizationCanAlwaysGoBackToDraft(): void
    {
        $this->messageBus->dispatch(new UnpublishOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND));

        self::assertTrue($this->isDraft('organization', OrganizationFixture::ORGANIZATION_RIVERBEND));
    }

    public function testAnEventWithoutParticipantsResultsOrTimesGoesBackToDraft(): void
    {
        $this->messageBus->dispatch(new UnpublishCompetition(OrganizationFixture::COMPETITION_RIVERBEND_OPEN));

        self::assertTrue($this->isDraft('competition', OrganizationFixture::COMPETITION_RIVERBEND_OPEN));
    }

    public function testParticipantsKeepAnEventPublished(): void
    {
        // Two going and one waitlisted (EventsPageFixture)
        $this->assertUnpublishRefused(new UnpublishCompetition(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN), [UnpublishBlocker::Participants]);
        self::assertFalse($this->isDraft('competition', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));
    }

    public function testAParticipantWhoLeftDoesNotCount(): void
    {
        $this->connection->executeStatement(
            'UPDATE competition_participant SET deleted_at = NOW() WHERE competition_id = :id',
            ['id' => EventsPageFixture::COMPETITION_RIVERSIDE_OPEN],
        );

        $this->messageBus->dispatch(new UnpublishCompetition(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));

        self::assertTrue($this->isDraft('competition', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));
    }

    public function testOfficialResultsKeepAnEventPublished(): void
    {
        // Only the official results stay: nobody joined any more, no solving time is linked
        $cup = OfficialResultsFixture::COMPETITION_RESULTS_CUP;
        $this->connection->executeStatement('UPDATE competition_participant SET deleted_at = NOW() WHERE competition_id = :id', ['id' => $cup]);
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = NULL, competition_round_id = NULL
             WHERE competition_id = :id OR competition_round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)',
            ['id' => $cup],
        );

        $this->assertUnpublishRefused(new UnpublishCompetition($cup), [UnpublishBlocker::Results]);
    }

    public function testALinkedSolvingTimeKeepsAnEventPublished(): void
    {
        $this->linkASolvingTimeTo(OrganizationFixture::COMPETITION_RIVERBEND_OPEN);

        $this->assertUnpublishRefused(new UnpublishCompetition(OrganizationFixture::COMPETITION_RIVERBEND_OPEN), [UnpublishBlocker::SolvingTimes]);
        self::assertFalse($this->isDraft('competition', OrganizationFixture::COMPETITION_RIVERBEND_OPEN));
    }

    public function testASeriesGoesBackToDraftOnlyWhileNoneOfItsEditionsHoldsAnything(): void
    {
        $this->messageBus->dispatch(new UnpublishCompetitionSeries(OrganizationFixture::SERIES_HARBOR_CLUB_MEETS));
        self::assertTrue($this->isDraft('competition_series', OrganizationFixture::SERIES_HARBOR_CLUB_MEETS));

        // A time linked to one edition of several is enough
        $this->linkASolvingTimeTo(OrganizationFixture::EDITION_LANTERN_2);

        $this->assertUnpublishRefused(new UnpublishCompetitionSeries(OrganizationFixture::SERIES_LANTERN_NIGHTS), [UnpublishBlocker::SolvingTimes]);
        self::assertFalse($this->isDraft('competition_series', OrganizationFixture::SERIES_LANTERN_NIGHTS));
    }

    /**
     * @param list<UnpublishBlocker> $expectedBlockers
     */
    private function assertUnpublishRefused(UnpublishCompetition|UnpublishCompetitionSeries $message, array $expectedBlockers): void
    {
        $dispatch = function () use ($message): void {
            $this->messageBus->dispatch($message);
        };

        try {
            $dispatch();
            self::fail('CannotUnpublish expected');
        } catch (CannotUnpublish $exception) {
            self::assertSame($expectedBlockers, $exception->blockers);
        }
    }

    private function linkASolvingTimeTo(string $competitionId): void
    {
        $this->connection->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competition
             WHERE id = (SELECT id FROM puzzle_solving_time WHERE competition_id IS NULL ORDER BY id LIMIT 1)',
            ['competition' => $competitionId],
        );
    }

    private function isDraft(string $table, string $id): bool
    {
        return (bool) $this->connection->fetchOne("SELECT is_draft FROM {$table} WHERE id = :id", ['id' => $id]);
    }
}
