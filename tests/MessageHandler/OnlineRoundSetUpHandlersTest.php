<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineLinkNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotAvailable;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Message\EnableOnlineRound;
use SpeedPuzzling\Web\Message\GenerateOnlineTokens;
use SpeedPuzzling\Web\Message\RegenerateOnlineToken;
use SpeedPuzzling\Web\Message\UpdateOnlineRoundSettings;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use SpeedPuzzling\Web\Value\OnlineToken;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The organiser's set-up of online timing (docs/features/online-competitions/, contract §4, stream C1):
 * EnableOnlineRound, UpdateOnlineRoundSettings, GenerateOnlineTokens, RegenerateOnlineToken - on the Online Test Cup
 * (OnlineRoundsFixture: Olivia and Arthur have links, Nora has none), with a second solo round and people added here.
 * Every assertion reads the rows back with SQL.
 */
final class OnlineRoundSetUpHandlersTest extends KernelTestCase
{
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string SECOND_ROUND = '018d0050-0000-0000-0000-0000000001a1';
    private const string PAIRS_ROUND = '018d0050-0000-0000-0000-0000000001a2';
    private const string REMOVED = '018d0050-0000-0000-0000-0000000002a1';
    private const string WAITLISTED = '018d0050-0000-0000-0000-0000000002a2';

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEnablingCreatesTheSettingsWithTheDefaultsAndGivesTheMissingLinks(): void
    {
        $this->addRound(self::SECOND_ROUND, 'Second Round', 'solo');
        $this->addPeopleWhoDoNotCompete();
        $before = $this->tokens();

        self::assertSame(1, $this->enable(self::SECOND_ROUND));

        $row = $this->database->fetchAssociative('SELECT * FROM online_round WHERE round_id = :round', ['round' => self::SECOND_ROUND]);
        self::assertIsArray($row);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['enabled_by_id']);
        self::assertSame(OnlineDetectionMode::Off->value, $row['detection_mode']);
        self::assertSame(OnlineUnfinishedMeasure::PiecesLeft->value, $row['unfinished_measure']);
        self::assertSame(OnlineMissingPieceRule::CompleteRequired->value, $row['missing_piece_rule']);
        self::assertFalse($row['video_enabled']);
        self::assertFalse($row['participants_see_each_other']);
        self::assertFalse($row['studio_enabled']);
        self::assertFalse($row['recording_enabled']);

        $after = $this->tokens();
        // Links already given stay; Nora (going, none yet) got hers; removed and waitlisted people get none
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $after[OnlineRoundsFixture::PARTICIPANT_CONNECTED]);
        self::assertSame(OnlineRoundsFixture::TOKEN_ANONYMOUS, $after[OnlineRoundsFixture::PARTICIPANT_ANONYMOUS]);
        self::assertNull($before[OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);
        self::assertIsString($after[OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);
        self::assertTrue(OnlineToken::looksValid($after[OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]));
        self::assertNull($after[self::REMOVED]);
        self::assertNull($after[self::WAITLISTED]);
        self::assertNotNull($this->database->fetchOne(
            'SELECT online_token_created_at FROM competition_participant WHERE id = :id',
            ['id' => OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN],
        ));
    }

    public function testEnablingAgainChangesNothing(): void
    {
        $this->addRound(self::SECOND_ROUND, 'Second Round', 'solo');
        $this->enable(self::SECOND_ROUND);
        $tokens = $this->tokens();
        $settings = $this->database->fetchAssociative('SELECT * FROM online_round WHERE round_id = :round', ['round' => self::SECOND_ROUND]);

        self::assertSame(0, $this->enable(self::SECOND_ROUND));
        // The fixture's round, on already
        self::assertSame(0, $this->enable(OnlineRoundsFixture::ROUND_ONLINE_SOLO));

        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM online_round WHERE round_id = :round', ['round' => self::SECOND_ROUND]));
        self::assertSame($settings, $this->database->fetchAssociative('SELECT * FROM online_round WHERE round_id = :round', ['round' => self::SECOND_ROUND]));
        self::assertSame($tokens, $this->tokens());
        self::assertSame(OnlineRoundsFixture::ONLINE_ROUND_SOLO, $this->database->fetchOne('SELECT id FROM online_round WHERE round_id = :round', ['round' => OnlineRoundsFixture::ROUND_ONLINE_SOLO]));
    }

    public function testEnablingRefusesARoundThatCannotUseOnlineTiming(): void
    {
        $this->addRound(self::PAIRS_ROUND, 'Pairs Round', 'duo');

        try {
            $this->enable(self::PAIRS_ROUND);
            self::fail('A pair round has no online timing in the prototype');
        } catch (OnlineTimingNotAvailable $refused) {
            self::assertSame(OnlineTimingAvailability::NotSolo, $refused->availability);
        }

        try {
            $this->enable(OfficialResultsFixture::ROUND_GROUP_A, OfficialResultsFixture::COMPETITION_RESULTS_CUP);
            self::fail('An in-person event has no online timing');
        } catch (OnlineTimingNotAvailable $refused) {
            self::assertSame(OnlineTimingAvailability::InPersonEvent, $refused->availability);
        }

        self::assertSame(0, $this->database->fetchOne(
            'SELECT COUNT(*) FROM online_round WHERE round_id IN (:pairs, :groupA)',
            ['pairs' => self::PAIRS_ROUND, 'groupA' => OfficialResultsFixture::ROUND_GROUP_A],
        ));
        self::assertSame(0, $this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :competition AND online_token IS NOT NULL',
            ['competition' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        ));
    }

    public function testEnablingRefusesARoundOfAnotherEvent(): void
    {
        $this->expectException(CompetitionRoundNotFound::class);

        // Authorised for the Results Cup, a round of the Online Test Cup
        $this->enable(OnlineRoundsFixture::ROUND_ONLINE_SOLO, OfficialResultsFixture::COMPETITION_RESULTS_CUP);
    }

    public function testTheSettingsChangeAllAtOnce(): void
    {
        $this->messageBus->dispatch(new UpdateOnlineRoundSettings(
            roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO,
            detectionMode: OnlineDetectionMode::Shadow,
            videoEnabled: true,
            participantsSeeEachOther: true,
            studioEnabled: true,
            recordingEnabled: true,
            unfinishedMeasure: OnlineUnfinishedMeasure::PiecesPlaced,
            missingPieceRule: OnlineMissingPieceRule::PenaltyFiveSeconds,
        ));

        $row = $this->database->fetchAssociative('SELECT * FROM online_round WHERE id = :id', ['id' => OnlineRoundsFixture::ONLINE_ROUND_SOLO]);
        self::assertIsArray($row);
        self::assertSame('shadow', $row['detection_mode']);
        self::assertSame('placed', $row['unfinished_measure']);
        self::assertSame('penalty_5s', $row['missing_piece_rule']);
        self::assertTrue($row['video_enabled']);
        self::assertTrue($row['participants_see_each_other']);
        self::assertTrue($row['studio_enabled']);
        self::assertTrue($row['recording_enabled']);
        // Who turned it on and when stays
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['enabled_by_id']);
    }

    public function testSettingsOfARoundWithoutOnlineTimingAreRefused(): void
    {
        $this->expectException(OnlineRoundNotFound::class);

        $this->messageBus->dispatch(new UpdateOnlineRoundSettings(
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            detectionMode: OnlineDetectionMode::On,
            videoEnabled: false,
            participantsSeeEachOther: false,
            studioEnabled: false,
            recordingEnabled: false,
            unfinishedMeasure: OnlineUnfinishedMeasure::PiecesLeft,
            missingPieceRule: OnlineMissingPieceRule::CompleteRequired,
        ));
    }

    public function testGeneratingGivesLinksOnlyToWhoeverGoesWithoutOne(): void
    {
        $this->addPeopleWhoDoNotCompete();
        $before = $this->tokens();

        self::assertSame(1, $this->generate(self::COMPETITION));

        $after = $this->tokens();
        self::assertSame($before[OnlineRoundsFixture::PARTICIPANT_CONNECTED], $after[OnlineRoundsFixture::PARTICIPANT_CONNECTED]);
        self::assertSame($before[OnlineRoundsFixture::PARTICIPANT_ANONYMOUS], $after[OnlineRoundsFixture::PARTICIPANT_ANONYMOUS]);
        self::assertIsString($after[OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);
        self::assertNull($after[self::REMOVED]);
        self::assertNull($after[self::WAITLISTED]);

        // Nothing left to give
        self::assertSame(0, $this->generate(self::COMPETITION));
        self::assertSame($after, $this->tokens());
    }

    public function testAnEventWithoutOnlineTimingGetsNoLinks(): void
    {
        self::assertSame(0, $this->generate(OfficialResultsFixture::COMPETITION_RESULTS_CUP));
        self::assertSame(0, $this->database->fetchOne(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :competition AND online_token IS NOT NULL',
            ['competition' => OfficialResultsFixture::COMPETITION_RESULTS_CUP],
        ));
    }

    public function testANewLinkReplacesTheOldOneAndTheOldOneOpensNothing(): void
    {
        // The participant API's cached heartbeat lookup of the old link (stream A)
        $cache = self::getContainer()->get('cache.app');
        $cache->get('online_access_light.' . OnlineRoundsFixture::TOKEN_CONNECTED, static function (ItemInterface $item): string {
            return 'cached lookup';
        });

        $this->messageBus->dispatch(new RegenerateOnlineToken(self::COMPETITION, OnlineRoundsFixture::PARTICIPANT_CONNECTED));

        $token = $this->tokens()[OnlineRoundsFixture::PARTICIPANT_CONNECTED];
        self::assertIsString($token);
        self::assertTrue(OnlineToken::looksValid($token));
        self::assertNotSame(OnlineRoundsFixture::TOKEN_CONNECTED, $token);
        // The others keep theirs
        self::assertSame(OnlineRoundsFixture::TOKEN_ANONYMOUS, $this->tokens()[OnlineRoundsFixture::PARTICIPANT_ANONYMOUS]);

        $repository = self::getContainer()->get(CompetitionParticipantRepository::class);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $repository->getByOnlineToken($token)->id->toString());

        // The cached lookup of the old link is gone
        self::assertSame('looked up again', $cache->get('online_access_light.' . OnlineRoundsFixture::TOKEN_CONNECTED, static function (ItemInterface $item): string {
            return 'looked up again';
        }));

        $this->expectException(OnlineLinkNotFound::class);
        $repository->getByOnlineToken(OnlineRoundsFixture::TOKEN_CONNECTED);
    }

    public function testANewLinkForSomebodyWithoutOneIsTheirFirst(): void
    {
        $this->messageBus->dispatch(new RegenerateOnlineToken(self::COMPETITION, OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN));

        self::assertIsString($this->tokens()[OnlineRoundsFixture::PARTICIPANT_WITHOUT_TOKEN]);
    }

    public function testRemovedPeopleAndOtherEventsGetNoNewLink(): void
    {
        $this->addPeopleWhoDoNotCompete();

        foreach ([[self::COMPETITION, self::REMOVED], [OfficialResultsFixture::COMPETITION_RESULTS_CUP, OnlineRoundsFixture::PARTICIPANT_CONNECTED]] as [$competitionId, $participantId]) {
            try {
                $this->messageBus->dispatch(new RegenerateOnlineToken($competitionId, $participantId));
                self::fail('Not a participant of the event the organiser was authorised for: ' . $participantId);
            } catch (CompetitionParticipantNotFound) {
            }
        }

        try {
            $this->messageBus->dispatch(new RegenerateOnlineToken(self::COMPETITION, self::WAITLISTED));
            self::fail('A waitlisted participant holds no spot - no link');
        } catch (ParticipantIsWaitlisted) {
        }

        $tokens = $this->tokens();
        self::assertNull($tokens[self::REMOVED]);
        self::assertNull($tokens[self::WAITLISTED]);
        self::assertSame(OnlineRoundsFixture::TOKEN_CONNECTED, $tokens[OnlineRoundsFixture::PARTICIPANT_CONNECTED]);
    }

    private function enable(string $roundId, string $competitionId = self::COMPETITION): int
    {
        $result = $this->messageBus->dispatch(new EnableOnlineRound($competitionId, $roundId, PlayerFixture::PLAYER_ADMIN))
            ->last(HandledStamp::class)?->getResult();
        self::assertIsInt($result);

        return $result;
    }

    private function generate(string $competitionId): int
    {
        $result = $this->messageBus->dispatch(new GenerateOnlineTokens($competitionId))->last(HandledStamp::class)?->getResult();
        self::assertIsInt($result);

        return $result;
    }

    private function addRound(string $roundId, string $name, string $category): void
    {
        $this->database->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, minutes_limit, starts_at, slug, category)
             VALUES (:id, :competition, :name, 45, NOW() + INTERVAL '30 days', :slug, :category)",
            ['id' => $roundId, 'competition' => self::COMPETITION, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'category' => $category],
        );
    }

    /**
     * A removed participant and one on the waitlist - neither competes, neither gets a link
     */
    private function addPeopleWhoDoNotCompete(): void
    {
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, source, deleted_at) VALUES (:id, 'Rita Removed', :competition, 'imported', NOW())",
            ['id' => self::REMOVED, 'competition' => self::COMPETITION],
        );
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, source, registration_status, registered_at) VALUES (:id, 'Walter Waiting', :competition, 'self_joined', 'waitlisted', NOW())",
            ['id' => self::WAITLISTED, 'competition' => self::COMPETITION],
        );
    }

    /**
     * @return array<string, null|string> participant id => their personal link's token
     */
    private function tokens(): array
    {
        /** @var array<string, null|string> $tokens */
        $tokens = $this->database->fetchAllKeyValue(
            'SELECT id, online_token FROM competition_participant WHERE competition_id = :competition ORDER BY id',
            ['competition' => self::COMPETITION],
        );

        return $tokens;
    }
}
