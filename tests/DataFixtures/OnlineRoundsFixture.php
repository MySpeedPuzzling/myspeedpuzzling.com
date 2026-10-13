<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\OnlineEntry;
use SpeedPuzzling\Web\Entity\OnlineEntryEvent;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\OnlineEventType;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * "Online Test Cup" - an online one-time event with online timing (docs/features/online-competitions/), set up by
 * PLAYER_ADMIN like the real test cups: a draft, so it is on no public list. Its own competition, so no other fixture's
 * rounds change. See .claude/fixtures.md "Online rounds".
 */
final class OnlineRoundsFixture extends Fixture implements DependentFixtureInterface
{
    public const string COMPETITION_ONLINE_TEST_CUP = '018d0050-0000-0000-0000-000000000001';

    public const string ROUND_ONLINE_SOLO = '018d0050-0000-0000-0000-000000000101';
    public const string ROUND_PUZZLE_ONLINE_SOLO = '018d0050-0000-0000-0000-000000000102';
    public const string ONLINE_ROUND_SOLO = '018d0050-0000-0000-0000-000000000151';

    // Connected to PLAYER_REGULAR, has a personal link and an online entry with events and a photo
    public const string PARTICIPANT_CONNECTED = '018d0050-0000-0000-0000-000000000201';
    // No player, has a personal link, entered in the round, its phone never made contact
    public const string PARTICIPANT_ANONYMOUS = '018d0050-0000-0000-0000-000000000202';
    // No player, no personal link yet (added after the links were made), not entered
    public const string PARTICIPANT_WITHOUT_TOKEN = '018d0050-0000-0000-0000-000000000203';

    // 22 characters of base58 (OnlineToken::looksValid())
    public const string TOKEN_CONNECTED = '2ConnectedTestCupToken';
    public const string TOKEN_ANONYMOUS = 'AnonymousTestCupToken2';

    // Round entries (CompetitionParticipantRound)
    public const string ENTRY_CONNECTED = '018d0050-0000-0000-0000-000000000301';
    public const string ENTRY_ANONYMOUS = '018d0050-0000-0000-0000-000000000302';

    public const string ONLINE_ENTRY_CONNECTED = '018d0050-0000-0000-0000-000000000401';
    public const string DEVICE_CONNECTED = 'fixture-device-connected';

    // The phone's own ids - UUIDv7
    public const string EVENT_CONSENT_CONNECTED = '018d0050-0000-7000-8000-000000000501';
    public const string EVENT_READY_CONNECTED = '018d0050-0000-7000-8000-000000000502';

    public const string MEDIA_EMPTY_MAT_CONNECTED = '018d0050-0000-0000-0000-000000000601';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $regularPlayer = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        $now = $this->clock->now();
        $roundStartsAt = $now->modify('+21 days')->setTime(18, 0);
        $setUpAt = $now->modify('-1 day')->setTime(10, 0);

        $competition = new Competition(
            id: Uuid::fromString(self::COMPETITION_ONLINE_TEST_CUP),
            name: 'Online Test Cup',
            slug: 'online-test-cup',
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: null,
            locationCountryCode: null,
            dateFrom: $roundStartsAt,
            dateTo: $roundStartsAt,
            tag: null,
            isOnline: true,
            addedByPlayer: $admin,
            approvedAt: $setUpAt,
            approvedByPlayer: $admin,
            createdAt: $setUpAt,
            isDraft: true,
        );
        $manager->persist($competition);
        $this->addReference(self::COMPETITION_ONLINE_TEST_CUP, $competition);

        $round = new CompetitionRound(
            id: Uuid::fromString(self::ROUND_ONLINE_SOLO),
            competition: $competition,
            name: 'Online Round',
            minutesLimit: 60,
            startsAt: $roundStartsAt,
            category: RoundCategory::Solo,
            slug: 'online-round',
            timezone: 'Europe/Prague',
        );
        $manager->persist($round);
        $this->addReference(self::ROUND_ONLINE_SOLO, $round);

        $manager->persist(new CompetitionRoundPuzzle(
            id: Uuid::fromString(self::ROUND_PUZZLE_ONLINE_SOLO),
            round: $round,
            puzzle: $this->getReference(PuzzleFixture::PUZZLE_3000, Puzzle::class),
        ));

        // Online timing with the Phase 1 defaults: no detection, no video
        $onlineRound = new OnlineRound(
            id: Uuid::fromString(self::ONLINE_ROUND_SOLO),
            round: $round,
            enabledAt: $setUpAt,
            enabledBy: $admin,
        );
        $manager->persist($onlineRound);
        $this->addReference(self::ONLINE_ROUND_SOLO, $onlineRound);

        $connected = $this->participant($manager, self::PARTICIPANT_CONNECTED, 'Olivia Online', 'cz', $competition, self::TOKEN_CONNECTED, $setUpAt);
        $connected->connect($regularPlayer, $setUpAt->modify('+2 hours'));
        // She opened her link signed in when her phone first made contact - the event page hands it to her
        $connected->confirmOnlineLink($setUpAt->modify('+3 hours'));
        $anonymous = $this->participant($manager, self::PARTICIPANT_ANONYMOUS, 'Arthur Anonymous', 'gb', $competition, self::TOKEN_ANONYMOUS, $setUpAt);
        $this->participant($manager, self::PARTICIPANT_WITHOUT_TOKEN, 'Nora Notoken', 'de', $competition, null, $setUpAt);

        $connectedEntry = new CompetitionParticipantRound(Uuid::fromString(self::ENTRY_CONNECTED), $connected, $round);
        $manager->persist($connectedEntry);
        $this->addReference(self::ENTRY_CONNECTED, $connectedEntry);

        $anonymousEntry = new CompetitionParticipantRound(Uuid::fromString(self::ENTRY_ANONYMOUS), $anonymous, $round);
        $manager->persist($anonymousEntry);
        $this->addReference(self::ENTRY_ANONYMOUS, $anonymousEntry);

        // Olivia's phone went through the set-up yesterday: consent, the empty mat photo, ready
        $firstSeenAt = $setUpAt->modify('+3 hours');
        $firstSeenMs = RoundClock::milliseconds($firstSeenAt);

        $onlineEntry = new OnlineEntry(
            id: Uuid::fromString(self::ONLINE_ENTRY_CONNECTED),
            entry: $connectedEntry,
            firstSeenAt: $firstSeenAt,
        );
        $onlineEntry->activeDeviceId = self::DEVICE_CONNECTED;
        $onlineEntry->readyAt = $firstSeenAt->modify('+5 minutes');
        $onlineEntry->clockOffsetMs = -12.5;
        $onlineEntry->clockRttMs = 38.0;
        $manager->persist($onlineEntry);
        $this->addReference(self::ONLINE_ENTRY_CONNECTED, $onlineEntry);

        $this->event($manager, self::EVENT_CONSENT_CONNECTED, $onlineEntry, OnlineEventType::ConsentGiven, $firstSeenMs + 1_000, []);
        $this->event($manager, self::EVENT_READY_CONNECTED, $onlineEntry, OnlineEventType::DeviceReady, $firstSeenMs + 300_000, [
            'browser' => 'fixture',
            'camera' => ['w' => 1920, 'h' => 1080, 'fps' => 30],
        ]);

        $media = new OnlineEntryMedia(
            id: Uuid::fromString(self::MEDIA_EMPTY_MAT_CONNECTED),
            onlineEntry: $onlineEntry,
            kind: OnlineMediaKind::EmptyMat,
            takenMs: $firstSeenMs + 240_000,
            store: OnlineMediaStore::Primary,
            objectKey: sprintf('online/%s/%s/%s/%s.jpg', self::COMPETITION_ONLINE_TEST_CUP, self::ROUND_ONLINE_SOLO, self::PARTICIPANT_CONNECTED, self::MEDIA_EMPTY_MAT_CONNECTED),
            contentType: 'image/jpeg',
            declaredBytes: 182_000,
            createdAt: $firstSeenAt->modify('+4 minutes'),
        );
        $media->markUploaded($firstSeenMs + 241_500);
        $media->markVerified(182_000);
        $manager->persist($media);
        $this->addReference(self::MEDIA_EMPTY_MAT_CONNECTED, $media);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            PuzzleFixture::class,
        ];
    }

    private function participant(
        ObjectManager $manager,
        string $id,
        string $name,
        string $country,
        Competition $competition,
        null|string $token,
        DateTimeImmutable $tokenCreatedAt,
    ): CompetitionParticipant {
        $participant = new CompetitionParticipant(
            id: Uuid::fromString($id),
            name: $name,
            country: $country,
            competition: $competition,
            source: ParticipantSource::Imported,
        );

        if ($token !== null) {
            $participant->assignOnlineToken($token, $tokenCreatedAt);
        }

        $manager->persist($participant);
        $this->addReference($id, $participant);

        return $participant;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function event(ObjectManager $manager, string $id, OnlineEntry $onlineEntry, OnlineEventType $type, int $clientMs, array $payload): void
    {
        $event = new OnlineEntryEvent(
            id: Uuid::fromString($id),
            onlineEntry: $onlineEntry,
            deviceId: self::DEVICE_CONNECTED,
            type: $type,
            clientMs: $clientMs,
            perfMs: 1_234.5,
            offsetMs: -12.5,
            rttMs: 38.0,
            receivedAtMs: $clientMs + 120,
            payload: $payload,
        );
        $manager->persist($event);
        $this->addReference($id, $event);
    }
}
