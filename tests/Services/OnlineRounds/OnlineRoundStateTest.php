<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryEventRepository;
use SpeedPuzzling\Web\Repository\OnlineEntryRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccess;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineRoundState;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The state a participant's page lives on - the JSON of docs/features/online-competitions/implementation-plan.md §3.1
 * `state`, read against OnlineRoundsFixture.
 *
 * @phpstan-import-type OnlineState from OnlineRoundState
 */
final class OnlineRoundStateTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MockClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->clock = new MockClock(new DateTimeImmutable('2026-10-13 12:00:00.250', new DateTimeZone('UTC')));
    }

    public function testTheConnectedParticipantsState(): void
    {
        $state = $this->state(OnlineRoundsFixture::TOKEN_CONNECTED);
        $hash = $state['round']['stateHash'] ?? null;
        $mercure = $state['mercure'];

        self::assertIsString($hash);
        self::assertNotNull($mercure);
        self::assertSame([
            'serverNowMs' => 1_791_892_800_250,
            'asOfMs' => 1_791_892_800_250,
            'participant' => [
                'id' => OnlineRoundsFixture::PARTICIPANT_CONNECTED,
                'name' => 'Olivia Online',
                'connectedPlayer' => [
                    'id' => PlayerFixture::PLAYER_REGULAR,
                    'name' => PlayerFixture::PLAYER_REGULAR_NAME,
                    'code' => 'player1',
                ],
            ],
            'competition' => [
                'id' => OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP,
                'name' => 'Online Test Cup',
            ],
            'round' => [
                'id' => OnlineRoundsFixture::ROUND_ONLINE_SOLO,
                'name' => 'Online Round',
                'minutesLimit' => 60,
                'piecesTotal' => 3000,
                'status' => RoundClock::WAITING,
                'startedAtMs' => null,
                'stoppedAtMs' => null,
                'endsAtMs' => null,
                'stateHash' => $hash,
                'settings' => [
                    'detection' => 'off',
                    'video' => false,
                    'seeEachOther' => false,
                    'studio' => false,
                    'unfinishedMeasure' => 'left',
                ],
            ],
            'entry' => [
                'confirmedMomentMs' => null,
                'candidates' => 0,
                'taps' => 0,
                'reportedPieces' => null,
                'review' => 'pending',
            ],
            'mercure' => $mercure,
        ], $state);

        // The round's public stopwatch topic only - never a results topic
        self::assertSame([OfficialResultsSubscription::stopwatchTopic(OnlineRoundsFixture::ROUND_ONLINE_SOLO)], $mercure['topics']);
        self::assertNotSame('', $mercure['token']);
        self::assertSame(OfficialResultsSubscription::LIFETIME_SECONDS, $mercure['expiresIn']);

        self::assertJson(json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function testAPhoneThatNeverMadeContactHasTheEntrysDefaults(): void
    {
        $state = $this->state(OnlineRoundsFixture::TOKEN_ANONYMOUS);

        self::assertNull($state['participant']['connectedPlayer']);
        self::assertSame(['confirmedMomentMs' => null, 'candidates' => 0, 'taps' => 0, 'reportedPieces' => null, 'review' => 'pending'], $state['entry']);
    }

    public function testTapsCountTheFinishesThatWereNotUndone(): void
    {
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        self::assertNotNull($onlineEntry);

        foreach ([OnlineEventType::FinishButton, OnlineEventType::FinishButtonUndone, OnlineEventType::FinishButton, OnlineEventType::FinishCandidate] as $offset => $type) {
            $this->entityManager->persist(new OnlineEntryEvent(Uuid::uuid7(), $onlineEntry, OnlineRoundsFixture::DEVICE_CONNECTED, $type, 1_792_002_832_345 + $offset, null, null, null, 1_792_002_833_000 + $offset));
        }

        $onlineEntry->reportedPieces = 12;
        $this->entityManager->flush();

        $entry = $this->state(OnlineRoundsFixture::TOKEN_CONNECTED)['entry'];
        self::assertNotNull($entry);

        self::assertSame(['taps' => 1, 'candidates' => 1, 'reportedPieces' => 12], [
            'taps' => $entry['taps'],
            'candidates' => $entry['candidates'],
            'reportedPieces' => $entry['reportedPieces'],
        ]);
    }

    public function testAConfirmedResultCarriesItsMoment(): void
    {
        $onlineEntry = $this->entityManager->find(OnlineEntry::class, OnlineRoundsFixture::ONLINE_ENTRY_CONNECTED);
        $reviewer = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_ADMIN);
        self::assertNotNull($onlineEntry);
        self::assertNotNull($reviewer);

        $onlineEntry->confirm(1_792_002_832_345, OnlineConfirmedSource::Button, $reviewer, new DateTimeImmutable());
        $this->entityManager->flush();

        $entry = $this->state(OnlineRoundsFixture::TOKEN_CONNECTED)['entry'];
        self::assertNotNull($entry);

        self::assertSame(['confirmedMomentMs' => 1_792_002_832_345, 'review' => 'confirmed'], [
            'confirmedMomentMs' => $entry['confirmedMomentMs'],
            'review' => $entry['review'],
        ]);
    }

    public function testAParticipantNotEnteredInTheRoundHasNoEntry(): void
    {
        $participant = $this->entityManager->find(CompetitionParticipant::class, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken('NoraHasALinkNow2222222', new DateTimeImmutable());
        $this->entityManager->flush();

        $state = $this->state('NoraHasALinkNow2222222');
        self::assertNotNull($state['round']);

        self::assertSame(OnlineRoundsFixture::ROUND_ONLINE_SOLO, $state['round']['id']);
        self::assertNull($state['entry']);
    }

    public function testACompetitionWithoutOnlineTimingHasNoRoundNoEntryNoSubscription(): void
    {
        $participant = $this->entityManager->find(CompetitionParticipant::class, OfficialResultsFixture::PARTICIPANT_BEN);
        self::assertNotNull($participant);
        $participant->assignOnlineToken('BenWithoutAnyRound2222', new DateTimeImmutable());
        $this->entityManager->flush();

        $state = $this->state('BenWithoutAnyRound2222');

        self::assertSame('Results Cup', $state['competition']['name']);
        self::assertNull($state['round']);
        self::assertNull($state['entry']);
        self::assertNull($state['mercure']);
    }

    public function testAPuzzleKeptSecretEntirelyHidesItsPieceCount(): void
    {
        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, OnlineRoundsFixture::ROUND_PUZZLE_ONLINE_SOLO);
        self::assertNotNull($roundPuzzle);

        $roundPuzzle->changeReveal(PuzzleHideMode::Entirely, RoundPuzzleReveal::Manual, null);
        $this->entityManager->flush();
        $hidden = $this->state(OnlineRoundsFixture::TOKEN_CONNECTED)['round'];
        self::assertNotNull($hidden);
        self::assertNull($hidden['piecesTotal']);

        // Only its picture hidden: the piece count is no secret
        $roundPuzzle->changeReveal(PuzzleHideMode::ImageOnly, RoundPuzzleReveal::Manual, null);
        $this->entityManager->flush();
        $pictureHidden = $this->state(OnlineRoundsFixture::TOKEN_CONNECTED)['round'];
        self::assertNotNull($pictureHidden);
        self::assertSame(3000, $pictureHidden['piecesTotal']);
    }

    public function testAStartedRoundCarriesItsClock(): void
    {
        $context = $this->access()->forToken(OnlineRoundsFixture::TOKEN_CONNECTED);
        $round = $context->round;
        self::assertNotNull($round);

        $round->startStopwatch($this->clock->now()->modify('-5 minutes'));
        $this->entityManager->flush();

        $roundState = $this->state(OnlineRoundsFixture::TOKEN_CONNECTED)['round'];
        self::assertNotNull($roundState);

        self::assertSame(RoundClock::RUNNING, $roundState['status']);
        self::assertSame(1_791_892_500_000, $roundState['startedAtMs']);
        self::assertSame(1_791_892_500_000 + 3_600_000, $roundState['endsAtMs']);
        self::assertSame(RoundClock::fromRound($round, $this->clock->now())->stateHash(), $roundState['stateHash']);
    }

    /**
     * @return OnlineState
     */
    private function state(string $token): array
    {
        $container = self::getContainer();
        $roundState = new OnlineRoundState(
            $container->get(OnlineEntryRepository::class),
            $container->get(OnlineEntryEventRepository::class),
            $container->get(OfficialResultsSubscription::class),
            $this->clock,
        );

        return $roundState->forParticipant($this->access()->forToken($token));
    }

    private function access(): OnlineAccess
    {
        $container = self::getContainer();

        return new OnlineAccess(
            $container->get(CompetitionParticipantRepository::class),
            $container->get(CompetitionParticipantRoundRepository::class),
            $container->get(OnlineRoundRepository::class),
            $this->clock,
        );
    }
}
