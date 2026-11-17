<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Results\ParticipantImportPlan;
use SpeedPuzzling\Web\Results\ParticipantImportRowProfile;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The import names a player by id, #code or profile link (participant-linking.md §7.3, D7, R5).
 * WJPC 2024: "John Regular" (PLAYER_REGULAR), "Jane Unconnected" (nobody), "Secret Player" (PLAYER_PRIVATE).
 * PLAYER_ADMIN = #admin "Admin User", PLAYER_PRIVATE = #player2 (private - the planner runs without a viewer here).
 */
final class ParticipantImportPlayerReferenceTest extends KernelTestCase
{
    private const string WJPC = CompetitionFixture::COMPETITION_WJPC_2024;

    private ParticipantImportPlanner $planner;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->planner = self::getContainer()->get(ParticipantImportPlanner::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideReferencesOfAdmin(): iterable
    {
        yield 'uuid' => [PlayerFixture::PLAYER_ADMIN];
        yield 'uuid upper-cased' => [strtoupper(PlayerFixture::PLAYER_ADMIN)];
        yield '#code' => ['#admin'];
        yield 'bare code, other case' => ['ADMIN'];
        yield 'profile link' => ['https://myspeedpuzzling.com/en/player-profile/' . PlayerFixture::PLAYER_ADMIN];
        yield 'profile link of another language, trailing slash and query' => ['https://myspeedpuzzling.com/profil-hrace/' . PlayerFixture::PLAYER_ADMIN . '/?tab=stats'];
        yield 'link without a scheme' => ['myspeedpuzzling.com/de/spieler-profil/' . PlayerFixture::PLAYER_ADMIN];
    }

    #[DataProvider('provideReferencesOfAdmin')]
    public function testEveryKindOfReferenceLinksThePlayer(string $cell): void
    {
        $plan = $this->plan([self::row(2, 'Jane Unconnected', $cell, CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)]);

        self::assertSame(ParticipantImportRowAction::Update, $plan->rows[0]->action);
        self::assertSame([['field' => 'msp_player_id', 'before' => null, 'after' => PlayerFixture::PLAYER_ADMIN]], $plan->rows[0]->changes);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, self::operations($plan)->participants[0]['connectPlayerId']);

        self::assertEquals(new ParticipantImportRowProfile(
            linkedNow: true,
            code: 'ADMIN',
            name: 'Admin User',
            masked: false,
            fileReference: $cell,
        ), $plan->rows[0]->profile);

        // R5: the names differ - linked anyway, but said
        self::assertSame([
            sprintf('Row 2: "Jane Unconnected" is linked to %s, whose profile is called "Admin User". If that is somebody else, correct the code in the file.', $cell),
        ], $this->texts($plan->rows[0]->messages));
    }

    /**
     * D11 (participant-linking.md, L2a): a refused pair - declined, "Not this person", "Not me" - is never linked again
     * by a file (`link_refused_before`); another player for the row still is
     */
    public function testARefusedPairIsNotLinkedByTheImport(): void
    {
        $this->database->executeStatement(
            "INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
             VALUES (:id, :competition, :participant, 'Jane Unconnected', :player, 'Admin User', 'not_this_person', NULL, NULL, now())",
            ['id' => Uuid::uuid7()->toString(), 'competition' => self::WJPC, 'participant' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, 'player' => PlayerFixture::PLAYER_ADMIN],
        );

        $plan = $this->plan([self::row(2, 'Jane Unconnected', '#admin', CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)]);

        self::assertNull(self::operations($plan)->participants[0]['connectPlayerId'] ?? null);
        self::assertContains(
            'Row 2: "Jane Unconnected" was refused for #admin before (declined, or "Not this person") - not linked again by an import. Link it in the participants sheet if it is right.',
            $this->texts($plan->rows[0]->messages),
        );

        $plan = $this->plan([self::row(2, 'Jane Unconnected', PlayerFixture::PLAYER_WITH_STRIPE, CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)]);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, self::operations($plan)->participants[0]['connectPlayerId']);
    }

    public function testTheSameNameKeyIsNoNameDifference(): void
    {
        $plan = $this->plan([self::row(2, 'admin  USER', '#admin')]);

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame([], $plan->rows[0]->messages);
        self::assertSame('new:2', self::operations($plan)->participants[0]['key']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, self::operations($plan)->participants[0]['connectPlayerId']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideReferencesOfNobody(): iterable
    {
        yield 'unknown code' => ['#nobody7', 'Row 2: no MySpeedPuzzling player #nobody7 - the row is imported without a profile.'];
        yield 'unknown uuid' => ['00000000-0000-0000-0000-000000000099', 'Row 2: no MySpeedPuzzling player 00000000-0000-0000-0000-000000000099 - the row is imported without a profile.'];
        yield 'too short for a code' => ['#ab', 'Row 2: "#ab" is not a MySpeedPuzzling #code, player id or profile link - the row is imported without a profile.'];
        yield 'not a code character' => ['ad-min', 'Row 2: "ad-min" is not a MySpeedPuzzling #code, player id or profile link - the row is imported without a profile.'];
        yield 'link not ending in an id' => ['https://myspeedpuzzling.com/en/player-profile/admin', 'Row 2: "https://myspeedpuzzling.com/en/player-profile/admin" is not a MySpeedPuzzling #code, player id or profile link - the row is imported without a profile.'];
    }

    #[DataProvider('provideReferencesOfNobody')]
    public function testAReferenceToNobodyImportsTheRowWithoutAProfile(string $cell, string $message): void
    {
        $plan = $this->plan([self::row(2, 'Casey Newcomer', $cell)]);

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertNull(self::operations($plan)->participants[0]['connectPlayerId']);
        self::assertNull($plan->rows[0]->profile);
        self::assertSame([$message], $this->texts($plan->rows[0]->messages));
    }

    public function testACodeThatChangedOwnerIsNeverGuessed(): void
    {
        // Michael Johnson had "admin" before Admin User took it
        $this->codeChange(PlayerFixture::PLAYER_WITH_FAVORITES, 'admin', 'player3');

        $plan = $this->plan([self::row(2, 'Jane Unconnected', '#admin', CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)]);

        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action);
        self::assertSame([
            'Row 2: the code #admin has belonged to more than one player, so it is not linked to anybody - the row is imported without a profile. Ask the person for their profile link.',
        ], $this->texts($plan->rows[0]->messages));
    }

    public function testAPlayersOwnFormerCodeIsTheirs(): void
    {
        $this->codeChange(PlayerFixture::PLAYER_ADMIN, 'oldadm', 'admin');
        // Changed back and forth - still one player
        $this->codeChange(PlayerFixture::PLAYER_REGULAR, 'player1', 'tmp1');
        $this->codeChange(PlayerFixture::PLAYER_REGULAR, 'tmp1', 'player1');

        $plan = $this->plan([
            self::row(2, 'Admin User', '#OldAdm'),
            self::row(3, 'John Regular', 'tmp1'),
        ]);

        self::assertSame(PlayerFixture::PLAYER_ADMIN, self::operations($plan)->participants[0]['connectPlayerId']);
        self::assertSame([], $plan->rows[0]->messages);
        // tmp1 named John Regular's player, who is his participant already
        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[1]->action);
        self::assertSame(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, $plan->rows[1]->participantId);
    }

    public function testTheFingerprintFollowsWhoACodeNames(): void
    {
        $rows = [self::row(2, 'Jane Unconnected', '#admin', CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED)];
        $before = $this->plan($rows)->fingerprint;

        $this->codeChange(PlayerFixture::PLAYER_WITH_FAVORITES, 'admin', 'player3');

        self::assertNotSame($before, $this->plan($rows)->fingerprint);
    }

    public function testTheSamePlayerForTwoPeopleLinksNeither(): void
    {
        $plan = $this->plan([
            self::row(2, 'Jane Unconnected', '#admin', CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED),
            self::row(3, 'Casey Newcomer', PlayerFixture::PLAYER_ADMIN),
            self::row(4, 'Robin Elsewhere', '#player3'),
        ]);

        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action);
        self::assertSame(['Row 2: the MySpeedPuzzling player #admin is in the file for different people (rows 2, 3), so none of these rows is linked to the profile.'], $this->texts($plan->rows[0]->messages));
        self::assertSame([sprintf('Row 3: the MySpeedPuzzling player %s is in the file for different people (rows 2, 3), so none of these rows is linked to the profile.', PlayerFixture::PLAYER_ADMIN)], $this->texts($plan->rows[1]->messages));

        $links = array_column(self::operations($plan)->participants, 'connectPlayerId', 'key');
        self::assertNull($links['new:3']);
        // Another player on another row is not affected
        self::assertSame([], array_filter($this->texts($plan->rows[2]->messages), static fn (string $text): bool => str_contains($text, 'different people')));
    }

    public function testRowsOfOnePersonNamingTheirPlayerAddUp(): void
    {
        $plan = $this->plan([
            self::row(2, 'Casey Newcomer', '#admin'),
            self::row(3, 'casey  newcomer', 'admin'),
        ]);

        self::assertSame(ParticipantImportRowAction::New, $plan->rows[0]->action);
        self::assertSame(ParticipantImportRowAction::SamePerson, $plan->rows[1]->action);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, self::operations($plan)->participants[0]['connectPlayerId']);
        self::assertTrue($plan->rows[1]->profile?->linkedNow);
    }

    public function testALinkThePlayerMadeThemselvesIsKeptAndSaidSo(): void
    {
        $kim = $this->participantLinkedTo('Kim Selfie', PlayerFixture::PLAYER_ADMIN, ParticipantLinkMethod::SelfJoin);
        $lee = $this->participantLinkedTo('Lee Listed', PlayerFixture::PLAYER_REGULAR, ParticipantLinkMethod::Organiser);
        $this->entityManager->flush();

        $plan = $this->plan([
            self::row(2, 'Kim Selfie', '#player3', $kim),
            self::row(3, 'Lee Listed', '#player4', $lee),
        ], CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertSame(['Row 2: "Kim Selfie" stays linked to the profile they linked themselves - "#player3" from the file ignored (an import never replaces a linked profile).'], $this->texts($plan->rows[0]->messages));
        self::assertEquals(new ParticipantImportRowProfile(false, 'ADMIN', 'Admin User', false, null, linkedByThemselves: true), $plan->rows[0]->profile);

        // The organiser's own link - today's words
        self::assertSame(['Row 3: "Lee Listed" stays connected to their MySpeedPuzzling player - msp_player_id "#player4" from the file ignored (an import never replaces a connected player).'], $this->texts($plan->rows[1]->messages));
        self::assertEquals(new ParticipantImportRowProfile(false, 'PLAYER1', 'John Doe', false, null), $plan->rows[1]->profile);
    }

    public function testTheKeptLinkNamedByTheFileShowsNoMessage(): void
    {
        $kim = $this->participantLinkedTo('Kim Selfie', PlayerFixture::PLAYER_ADMIN, ParticipantLinkMethod::Claim);
        $this->entityManager->flush();

        $plan = $this->plan([self::row(2, 'Kim Selfie', '#admin', $kim)], CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertSame(ParticipantImportRowAction::Unchanged, $plan->rows[0]->action);
        self::assertSame([], $plan->rows[0]->messages);
        self::assertEquals(new ParticipantImportRowProfile(false, 'ADMIN', 'Admin User', false, null, linkedByThemselves: true), $plan->rows[0]->profile);
    }

    public function testAProfileTheOrganiserMayNotSeeIsNeverNamed(): void
    {
        $plan = $this->plan([self::row(2, 'Mystery Guest', '#player2')], CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertSame(PlayerFixture::PLAYER_PRIVATE, self::operations($plan)->participants[0]['connectPlayerId']);
        self::assertEquals(new ParticipantImportRowProfile(true, null, null, true, '#player2'), $plan->rows[0]->profile);
        // No "name differs" - it would tell whether a guessed name is the private player's
        self::assertSame([], $plan->rows[0]->messages);
    }

    public function testARowWithoutAPlayerCellShowsNoProfileLine(): void
    {
        $plan = $this->plan([self::row(2, 'John Regular', null, CompetitionParticipantFixture::PARTICIPANT_CONNECTED)]);

        self::assertNull($plan->rows[0]->profile);
    }

    /**
     * @param list<ParticipantImportRowData> $rows
     */
    private function plan(array $rows, string $competitionId = self::WJPC): ParticipantImportPlan
    {
        return $this->planner->plan($competitionId, new ParticipantImportRows($rows), ParticipantImportMode::Update);
    }

    private static function row(int $number, string $name, null|string $player, null|string $participantId = null): ParticipantImportRowData
    {
        return new ParticipantImportRowData(rowNumber: $number, name: $name, playerId: $player, participantId: $participantId);
    }

    private function participantLinkedTo(string $name, string $playerId, ParticipantLinkMethod $via): string
    {
        $competition = $this->entityManager->find(Competition::class, CompetitionSeriesFixture::EDITION_OFFLINE_1);
        $player = $this->entityManager->find(Player::class, $playerId);
        assert($competition instanceof Competition && $player instanceof Player);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, 'cz', $competition, ParticipantSource::Imported);
        $participant->connect($player, TestClock::now(), $via);
        $this->entityManager->persist($participant);

        return $participant->id->toString();
    }

    private function codeChange(string $playerId, string $previousCode, string $newCode): void
    {
        $this->database->insert('player_code_change', [
            'id' => Uuid::uuid7()->toString(),
            'player_id' => $playerId,
            'previous_code' => $previousCode,
            'new_code' => $newCode,
            'changed_at' => TestClock::now()->format('Y-m-d H:i:s'),
        ]);
    }

    private static function operations(ParticipantImportPlan $plan): ParticipantImportOperations
    {
        assert($plan->operations instanceof ParticipantImportOperations);

        return $plan->operations;
    }

    /**
     * @param list<TranslatableMessage> $messages
     * @return list<string>
     */
    private function texts(array $messages): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        return array_map(static fn (TranslatableMessage $message): string => $message->trans($translator, 'en'), $messages);
    }
}
