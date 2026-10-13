<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccess;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What a personal link opens (docs/features/online-competitions/README.md §5.4): the participant, the competition and
 * the round the page is about now - a countdown or a running clock first, then the next round by schedule, else the
 * most recent one.
 */
final class OnlineAccessTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testALinkOpensItsParticipantCompetitionRoundAndEntry(): void
    {
        $now = new DateTimeImmutable('2026-10-13 12:00:00.250', new DateTimeZone('UTC'));
        $context = $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $context->participant->id->toString());
        self::assertSame(OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP, $context->competition->id->toString());
        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $context->round?->id->toString());
        self::assertSame(OnlineRoundsFixture::ONLINE_ROUND_SOLO, $context->onlineRound?->id->toString());
        self::assertSame(OnlineRoundsFixture::ENTRY_CONNECTED, $context->entry?->id->toString());
        self::assertSame(RoundClock::WAITING, $context->clock?->status);
        self::assertSame(RoundClock::milliseconds($now), $context->asOfMs);
    }

    public function testAnAnonymousLinkOpensItsOwnEntry(): void
    {
        $context = $this->access()->forToken(OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertSame(OnlineRoundsFixture::PARTICIPANT_ANONYMOUS, $context->participant->id->toString());
        self::assertNull($context->participant->player);
        self::assertSame(OnlineRoundsFixture::ENTRY_ANONYMOUS, $context->entry?->id->toString());
    }

    public function testAnUnknownOrMalformedLinkOpensNothing(): void
    {
        $opened = [];

        foreach (['NoSuchTokenAnywhere222', 'not a token', '', OnlineRoundsFixture::PARTICIPANT_CONNECTED] as $token) {
            try {
                $this->access()->forToken($token);
                $opened[] = $token;
            } catch (OnlineLinkNotFound) {
                // expected
            }
        }

        self::assertSame([], $opened);
    }

    public function testARemovedParticipantsLinkOpensNothing(): void
    {
        $participant = $this->entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_CONNECTED);
        self::assertNotNull($participant);
        $participant->softDelete(new DateTimeImmutable());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->expectException(OnlineLinkNotFound::class);
        $this->access()->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);
    }

    public function testAReplacedLinkOpensNothing(): void
    {
        $participant = $this->entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_CONNECTED);
        self::assertNotNull($participant);
        $participant->assignOnlineToken('NewLinkForTheConnected', new DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $this->access()->forToken('NewLinkForTheConnected')->participant->id->toString());

        $this->expectException(OnlineLinkNotFound::class);
        $this->access()->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);
    }

    public function testACompetitionWithoutOnlineTimingOpensNoRound(): void
    {
        $participant = $this->entityManager->find(CompetitionParticipant::class, OfficialResultsFixture::PARTICIPANT_BEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken('BenWithoutAnyRound2222', new DateTimeImmutable());
        $this->entityManager->flush();

        $context = $this->access()->forToken('BenWithoutAnyRound2222');

        self::assertSame(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $context->competition->id->toString());
        self::assertNull($context->round);
        self::assertNull($context->onlineRound);
        self::assertNull($context->entry);
        self::assertNull($context->clock);
    }

    public function testARunningRoundComesBeforeTheNextOneAndTheParticipantMayNotBeInIt(): void
    {
        $now = $this->fixtureRoundStartsAt()->modify('+3 days');
        // Scheduled after the fixture's round, running now (started 10 minutes ago)
        $running = $this->onlineRound('Running round', $now->modify('-10 minutes'), status: 'running', startedAt: $now->modify('-10 minutes'));

        $context = $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame($running->id->toString(), $context->round?->id->toString());
        self::assertSame(RoundClock::RUNNING, $context->clock?->status);
        self::assertNull($context->entry, 'not entered in that round');
    }

    public function testACountdownComesBeforeTheNextRound(): void
    {
        $now = $this->fixtureRoundStartsAt()->modify('-2 days');
        $scheduled = $this->onlineRound('Countdown round', $now->modify('+5 days'), status: 'scheduled', startedAt: $now->modify('+10 seconds'));

        $context = $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame($scheduled->id->toString(), $context->round?->id->toString());
        self::assertSame(RoundClock::SCHEDULED, $context->clock?->status);
    }

    public function testARunningRoundComesBeforeACountdown(): void
    {
        $now = $this->fixtureRoundStartsAt()->modify('-2 days');
        $this->onlineRound('Countdown round', $now->modify('-1 hour'), status: 'scheduled', startedAt: $now->modify('+10 seconds'));
        $running = $this->onlineRound('Running round', $now->modify('-2 hours'), status: 'running', startedAt: $now->modify('-5 minutes'));

        self::assertSame($running->id->toString(), $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED)->round?->id->toString());
    }

    public function testTheNextRoundNotRunYetComesBeforeTheOnesThatEnded(): void
    {
        $now = $this->fixtureRoundStartsAt()->modify('-2 days');
        // Ended yesterday, and one later than the fixture's round that has not run yet either
        $this->onlineRound('Yesterday', $now->modify('-1 day'), status: 'stopped', startedAt: $now->modify('-1 day'), stoppedAt: $now->modify('-1 day +40 minutes'));
        $this->onlineRound('Later', $now->modify('+10 days'));

        $context = $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $context->round?->id->toString());
        self::assertSame(OnlineRoundsFixture::ENTRY_CONNECTED, $context->entry?->id->toString());
    }

    public function testAnOrganiserLateToStartStillHasTheirRound(): void
    {
        // An hour past the fixture round's planned start, nobody pressed Start - a later round must not take over
        $now = $this->fixtureRoundStartsAt()->modify('+1 hour');
        $this->onlineRound('Tomorrow', $now->modify('+1 day'));

        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED)->round?->id->toString());
    }

    public function testWhenEveryRoundEndedTheMostRecentOneIsShown(): void
    {
        $fixtureRound = $this->entityManager->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNotNull($fixtureRound);
        $startsAt = $this->fixtureRoundStartsAt();
        $fixtureRound->startStopwatch($startsAt);
        $this->onlineRound('Earlier', $startsAt->modify('-1 day'), status: 'stopped', startedAt: $startsAt->modify('-1 day'), stoppedAt: $startsAt->modify('-1 day +30 minutes'));

        // Two hours after the fixture round started: its 60 minutes are over
        $context = $this->access($startsAt->modify('+2 hours'))->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);

        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $context->round?->id->toString());
        self::assertSame(RoundClock::ENDED, $context->clock?->status);
    }

    public function testARoundWithoutOnlineTimingIsNeverTheParticipantsRound(): void
    {
        $now = $this->fixtureRoundStartsAt()->modify('-2 days');
        $competition = $this->entityManager->find(Competition::class, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        self::assertNotNull($competition);

        $inPerson = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: 'In-person round',
            minutesLimit: 60,
            startsAt: $now->modify('-10 minutes'),
            stopwatchStartedAt: $now->modify('-10 minutes'),
            stopwatchStatus: 'running',
        );
        $this->entityManager->persist($inPerson);
        $this->entityManager->flush();

        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $this->access($now)->forToken(OnlineRoundsFixture::TOKEN_CONNECTED)->round?->id->toString());
    }

    private function access(null|DateTimeImmutable $now = null): OnlineAccess
    {
        $container = self::getContainer();

        return new OnlineAccess(
            $container->get(CompetitionParticipantRepository::class),
            $container->get(CompetitionParticipantRoundRepository::class),
            $container->get(OnlineRoundRepository::class),
            new MockClock($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))),
        );
    }

    private function fixtureRoundStartsAt(): DateTimeImmutable
    {
        $round = $this->entityManager->find(CompetitionRound::class, OnlineRoundsFixture::ROUND_ONLINE_SOLO);
        self::assertNotNull($round);

        return $round->startsAt;
    }

    private function onlineRound(
        string $name,
        DateTimeImmutable $startsAt,
        null|string $status = null,
        null|DateTimeImmutable $startedAt = null,
        null|DateTimeImmutable $stoppedAt = null,
    ): CompetitionRound {
        $competition = $this->entityManager->find(Competition::class, OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP);
        self::assertNotNull($competition);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: $name,
            minutesLimit: 60,
            startsAt: $startsAt,
            stopwatchStartedAt: $startedAt,
            stopwatchStatus: $status,
            stopwatchStoppedAt: $stoppedAt,
        );
        $this->entityManager->persist($round);
        $this->entityManager->persist(new OnlineRound(Uuid::uuid7(), $round, $startsAt->modify('-7 days'), null));
        $this->entityManager->flush();

        return $round;
    }
}
