<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Message\ImportCompetitionParticipants;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ImportCompetitionParticipantsHandlerTest extends KernelTestCase
{
    public function testImportsThroughTheBusIncludingRoundAssignments(): void
    {
        self::bootKernel();
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $database = self::getContainer()->get(Connection::class);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([
            ['name', 'country', 'external_id', 'round_name', 'msp_player_id'],
            ['Brand New Puzzler', 'es', 'brand-new-puzzler', 'Qualification Round', ''],
            ['Official Admin Name', 'cz', 'official-admin', 'Qualification Round', PlayerFixture::PLAYER_ADMIN],
        ]);
        $file = tempnam(sys_get_temp_dir(), 'test_import_');
        assert(is_string($file));
        (new Xlsx($spreadsheet))->save($file);

        // Runs inside doctrine_transaction, while the importer flushes twice (participants, then round assignments)
        $envelope = $messageBus->dispatch(new ImportCompetitionParticipants(CompetitionFixture::COMPETITION_WJPC_2024, $file));
        unlink($file);

        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ParticipantImportResult::class, $result);
        self::assertSame(2, $result->added);
        self::assertSame([], $result->errors);

        $assignedToRound = $database->fetchOne(
            'SELECT count(*) FROM competition_participant_round cpr
             JOIN competition_participant cp ON cp.id = cpr.participant_id
             WHERE cpr.round_id = :roundId AND cp.external_id IN (\'brand-new-puzzler\', \'official-admin\')',
            ['roundId' => CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
        );
        self::assertSame(2, $assignedToRound);

        self::assertSame('Official Admin Name', $database->fetchOne(
            'SELECT name FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => CompetitionFixture::COMPETITION_WJPC_2024, 'pid' => PlayerFixture::PLAYER_ADMIN],
        ));
    }
}
