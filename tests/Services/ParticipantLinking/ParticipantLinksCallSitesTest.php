<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantLinking;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Message\ImportCompetitionParticipants;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Message\LeaveCompetition;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ParticipantLinking\ParticipantLinks;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture as Online;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\ParticipantLinkAction;
use SpeedPuzzling\Web\Value\ParticipantLinkMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every link and unlink goes through ParticipantLinks (docs/features/competitions-management/participant-linking.md
 * §4.1): each call site records its method on the row (`connected_via`) and writes the participant_link_log.
 */
final class ParticipantLinksCallSitesTest extends KernelTestCase
{
    use SheetChangeSets;

    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testImMyOwnNewRowIsASelfJoin(): void
    {
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024,
            playerId: PlayerFixture::PLAYER_ADMIN,
        ));

        $participantId = $this->database->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, 'pid' => PlayerFixture::PLAYER_ADMIN],
        );
        self::assertIsString($participantId);

        self::assertSame('self_join', $this->connectedVia($participantId));
        self::assertSame([
            ['action' => 'linked', 'method' => 'self_join', 'player_id' => PlayerFixture::PLAYER_ADMIN, 'by_player_id' => PlayerFixture::PLAYER_ADMIN],
        ], $this->log($participantId));
    }

    public function testPickingANameIsAClaimAndReleasesTheOwnRows(): void
    {
        // PLAYER_REGULAR is linked to the organiser's row PARTICIPANT_CONNECTED
        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            participantId: Wjpc::PARTICIPANT_UNCONNECTED,
        ));

        self::assertSame('claim', $this->connectedVia(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame([
            ['action' => 'linked', 'method' => 'claim', 'player_id' => PlayerFixture::PLAYER_REGULAR, 'by_player_id' => PlayerFixture::PLAYER_REGULAR],
        ], $this->log(Wjpc::PARTICIPANT_UNCONNECTED));

        // The organiser's row stays on the list, unlinked - with nothing of the link left
        self::assertNull($this->connectedVia(Wjpc::PARTICIPANT_CONNECTED));
        self::assertNull($this->database->fetchOne('SELECT connected_at FROM competition_participant WHERE id = :id', ['id' => Wjpc::PARTICIPANT_CONNECTED]));
        self::assertSame([
            ['action' => 'unlinked', 'method' => null, 'player_id' => PlayerFixture::PLAYER_REGULAR, 'by_player_id' => PlayerFixture::PLAYER_REGULAR],
        ], $this->log(Wjpc::PARTICIPANT_CONNECTED));
    }

    public function testRePickingYourOwnNameKeepsTheLinkItsMomentAndMethod(): void
    {
        $this->database->executeStatement(
            "UPDATE competition_participant SET connected_at = TIMESTAMP '2026-09-01 10:00:00' WHERE id = :id",
            ['id' => Wjpc::PARTICIPANT_CONNECTED],
        );

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
            participantId: Wjpc::PARTICIPANT_CONNECTED,
        ));

        self::assertSame('2026-09-01 10:00:00', $this->database->fetchOne('SELECT connected_at FROM competition_participant WHERE id = :id', ['id' => Wjpc::PARTICIPANT_CONNECTED]));
        self::assertSame('import', $this->connectedVia(Wjpc::PARTICIPANT_CONNECTED));
        self::assertSame([], $this->log(Wjpc::PARTICIPANT_CONNECTED));
    }

    public function testLeavingUnlinksTheOrganisersRow(): void
    {
        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        self::assertNull($this->connectedVia(Wjpc::PARTICIPANT_CONNECTED));
        self::assertSame([
            ['action' => 'unlinked', 'method' => null, 'player_id' => PlayerFixture::PLAYER_REGULAR, 'by_player_id' => PlayerFixture::PLAYER_REGULAR],
        ], $this->log(Wjpc::PARTICIPANT_CONNECTED));
    }

    public function testJoiningAgainAfterLeavingIsANewSelfJoin(): void
    {
        // The self-joined row goes (soft delete, it keeps its player) - no link change to log
        $this->messageBus->dispatch(new LeaveCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));
        self::assertSame([], $this->log(Wjpc::PARTICIPANT_SELF_JOINED));

        $this->database->executeStatement(
            "UPDATE competition_participant SET connected_at = TIMESTAMP '2026-09-01 10:00:00' WHERE id = :id",
            ['id' => Wjpc::PARTICIPANT_SELF_JOINED],
        );
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->messageBus->dispatch(new JoinCompetition(
            competitionId: CompetitionFixture::COMPETITION_WJPC_2024,
            playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        self::assertSame(TestClock::FROZEN_NOW, $this->database->fetchOne('SELECT connected_at FROM competition_participant WHERE id = :id AND deleted_at IS NULL', ['id' => Wjpc::PARTICIPANT_SELF_JOINED]));
        self::assertSame('self_join', $this->connectedVia(Wjpc::PARTICIPANT_SELF_JOINED));
        self::assertSame([
            ['action' => 'linked', 'method' => 'self_join', 'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'by_player_id' => PlayerFixture::PLAYER_WITH_FAVORITES],
        ], $this->log(Wjpc::PARTICIPANT_SELF_JOINED));
    }

    public function testThePersonalLinkIsAnOnlineLinkAndLetsGoOfThePlayersOtherRow(): void
    {
        // PLAYER_REGULAR holds PARTICIPANT_CONNECTED and opens the anonymous participant's personal link
        $this->messageBus->dispatch(new ConnectCompetitionParticipant(
            competitionId: Online::COMPETITION_ONLINE_TEST_CUP,
            playerId: PlayerFixture::PLAYER_REGULAR,
            participantId: Online::PARTICIPANT_ANONYMOUS,
        ));

        self::assertSame('online_link', $this->connectedVia(Online::PARTICIPANT_ANONYMOUS));
        self::assertSame([
            ['action' => 'linked', 'method' => 'online_link', 'player_id' => PlayerFixture::PLAYER_REGULAR, 'by_player_id' => PlayerFixture::PLAYER_REGULAR],
        ], $this->log(Online::PARTICIPANT_ANONYMOUS));

        self::assertNull($this->connectedVia(Online::PARTICIPANT_CONNECTED));
        self::assertNull($this->database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => Online::PARTICIPANT_CONNECTED]));
        self::assertSame([
            ['action' => 'unlinked', 'method' => null, 'player_id' => PlayerFixture::PLAYER_REGULAR, 'by_player_id' => PlayerFixture::PLAYER_REGULAR],
        ], $this->log(Online::PARTICIPANT_CONNECTED));
    }

    public function testThePersonalLinkOfYourOwnRowChangesNothing(): void
    {
        $this->messageBus->dispatch(new ConnectCompetitionParticipant(
            competitionId: Online::COMPETITION_ONLINE_TEST_CUP,
            playerId: PlayerFixture::PLAYER_REGULAR,
            participantId: Online::PARTICIPANT_CONNECTED,
        ));

        self::assertSame([], $this->log(Online::PARTICIPANT_CONNECTED));
    }

    public function testAnImportedPlayerIdIsAnImportLink(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['name', 'country', 'external_id', 'msp_player_id'],
            ['Official Admin Name', 'cz', 'official-admin', PlayerFixture::PLAYER_ADMIN],
        ]);
        $file = tempnam(sys_get_temp_dir(), 'test_import_');
        assert(is_string($file));
        (new Xlsx($spreadsheet))->save($file);

        $this->messageBus->dispatch(new ImportCompetitionParticipants(CompetitionFixture::COMPETITION_WJPC_2024, $file));
        unlink($file);

        $participantId = $this->database->fetchOne(
            'SELECT id FROM competition_participant WHERE competition_id = :cid AND player_id = :pid',
            ['cid' => CompetitionFixture::COMPETITION_WJPC_2024, 'pid' => PlayerFixture::PLAYER_ADMIN],
        );
        self::assertIsString($participantId);

        self::assertSame('import', $this->connectedVia($participantId));
        self::assertSame([
            ['action' => 'linked', 'method' => 'import', 'player_id' => PlayerFixture::PLAYER_ADMIN, 'by_player_id' => null],
        ], $this->log($participantId));
    }

    public function testAnImportedCodeIsAnImportLink(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['name', 'code'],
            ['Jane Unconnected', '#ADMIN'],
        ]);
        $file = tempnam(sys_get_temp_dir(), 'test_import_');
        assert(is_string($file));
        (new Xlsx($spreadsheet))->save($file);

        $this->messageBus->dispatch(new ImportCompetitionParticipants(CompetitionFixture::COMPETITION_WJPC_2024, $file));
        unlink($file);

        self::assertSame('import', $this->connectedVia(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame([
            ['action' => 'linked', 'method' => 'import', 'player_id' => PlayerFixture::PLAYER_ADMIN, 'by_player_id' => null],
        ], $this->log(Wjpc::PARTICIPANT_UNCONNECTED));
    }

    public function testTheSheetsLinksAreTheOrganisersAndNameWhoMadeThem(): void
    {
        $this->applySheetChanges([
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_WITH_FAVORITES]),
            self::sheetGroup(['op' => 'player', 'participant' => Cup::PARTICIPANT_ANNA, 'from' => PlayerFixture::PLAYER_ADMIN, 'to' => null]),
        ]);

        self::assertSame('organiser', $this->connectedVia(Cup::PARTICIPANT_IVAN));
        self::assertSame([
            ['action' => 'linked', 'method' => 'organiser', 'player_id' => PlayerFixture::PLAYER_WITH_FAVORITES, 'by_player_id' => PlayerFixture::PLAYER_WITH_STRIPE],
        ], $this->log(Cup::PARTICIPANT_IVAN));

        self::assertNull($this->connectedVia(Cup::PARTICIPANT_ANNA));
        self::assertSame([
            ['action' => 'unlinked', 'method' => null, 'player_id' => PlayerFixture::PLAYER_ADMIN, 'by_player_id' => PlayerFixture::PLAYER_WITH_STRIPE],
        ], $this->log(Cup::PARTICIPANT_ANNA));
    }

    public function testLinkingARowOfSomebodyElseLogsTheUnlinkFirst(): void
    {
        $container = self::getContainer();
        $participant = $container->get(CompetitionParticipantRepository::class)->get(Wjpc::PARTICIPANT_CONNECTED);
        $admin = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN);
        $organiser = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE);

        $container->get(ParticipantLinks::class)->link($participant, $admin, ParticipantLinkMethod::Organiser, $organiser, TestClock::now());
        $container->get(EntityManagerInterface::class)->flush();

        self::assertSame('organiser', $this->connectedVia(Wjpc::PARTICIPANT_CONNECTED));
        $log = $this->log(Wjpc::PARTICIPANT_CONNECTED);
        usort($log, static fn (array $a, array $b): int => strcmp((string) $b['action'], (string) $a['action']));
        self::assertSame([
            ['action' => 'unlinked', 'method' => null, 'player_id' => PlayerFixture::PLAYER_REGULAR, 'by_player_id' => PlayerFixture::PLAYER_WITH_STRIPE],
            ['action' => 'linked', 'method' => 'organiser', 'player_id' => PlayerFixture::PLAYER_ADMIN, 'by_player_id' => PlayerFixture::PLAYER_WITH_STRIPE],
        ], $log);

        /** @var array{participant_name: string, player_name: string, competition_id: string} $copied */
        $copied = $this->database->fetchAssociative(
            'SELECT participant_name, player_name, competition_id FROM participant_link_log WHERE participant_id = :id AND action = :action',
            ['id' => Wjpc::PARTICIPANT_CONNECTED, 'action' => ParticipantLinkAction::Linked->value],
        );
        self::assertSame('John Regular', $copied['participant_name']);
        self::assertSame($admin->name ?? $admin->code, $copied['player_name']);
        self::assertSame(CompetitionFixture::COMPETITION_WJPC_2024, $copied['competition_id']);
    }

    private function connectedVia(string $participantId): null|string
    {
        $via = $this->database->fetchOne('SELECT connected_via FROM competition_participant WHERE id = :id', ['id' => $participantId]);
        self::assertTrue($via === null || is_string($via));

        return $via;
    }

    /**
     * @return list<array{action: string, method: null|string, player_id: string, by_player_id: null|string}>
     */
    private function log(string $participantId): array
    {
        /** @var list<array{action: string, method: null|string, player_id: string, by_player_id: null|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT action, method, player_id, by_player_id FROM participant_link_log WHERE participant_id = :id ORDER BY id',
            ['id' => $participantId],
        );

        return $rows;
    }
}
