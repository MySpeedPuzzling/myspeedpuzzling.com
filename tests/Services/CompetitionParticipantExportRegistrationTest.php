<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Services\CompetitionParticipantExporter;
use SpeedPuzzling\Web\Services\CompetitionParticipantImporter;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The export of an event that manages registration carries what the organiser sees - status, paid, checked in - after
 * every other column; importing it back changes nothing, and nothing of registration is read from a file (D16).
 */
final class CompetitionParticipantExportRegistrationTest extends KernelTestCase
{
    private const string WJPC = CompetitionFixture::COMPETITION_WJPC_2024;

    public function testExportOfAnEventWithoutManagedRegistrationEndsWithTheParticipantId(): void
    {
        self::bootKernel();

        $headers = $this->exportedRows()[0];

        self::assertSame('participant_id', end($headers));
        self::assertNotContains('registration_status', $headers);
    }

    public function testManagedExportAppendsTheRegistrationColumnsAndImportsBackAsNoChange(): void
    {
        self::bootKernel();
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $messageBus->dispatch(new ChangeCompetitionRegistrationSettings(self::WJPC, true, 10, null, null, 'Europe/Prague', null, null));
        $messageBus->dispatch(new MarkParticipantPaid(self::WJPC, CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        $messageBus->dispatch(new CheckInParticipant(self::WJPC, CompetitionParticipantFixture::PARTICIPANT_CONNECTED));
        $before = $this->registrationColumns();

        $rows = $this->exportedRows();
        $headers = $rows[0];
        self::assertSame(['participant_id', ...CompetitionParticipantExporter::REGISTRATION_HEADERS], array_slice($headers, -4));

        $connectedRow = array_values(array_filter($rows, static fn (array $row): bool => in_array(CompetitionParticipantFixture::PARTICIPANT_CONNECTED, $row, true)));
        self::assertCount(1, $connectedRow);
        self::assertSame('paid', $connectedRow[0][count($headers) - 3]);
        self::assertNotNull($connectedRow[0][count($headers) - 2]);
        self::assertNotNull($connectedRow[0][count($headers) - 1]);

        $file = tempnam(sys_get_temp_dir(), 'test_export_');
        assert(is_string($file));
        file_put_contents($file, self::getContainer()->get(CompetitionParticipantExporter::class)->export(self::WJPC));
        $result = self::getContainer()->get(CompetitionParticipantImporter::class)->import(self::WJPC, $file);
        unlink($file);

        self::assertSame([], $this->texts($result->warnings), 'The registration columns are known - no "unknown column" warning');
        self::assertSame([], $this->texts($result->errors));
        self::assertSame(0, $result->added);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->softDeleted);
        self::assertSame($before, $this->registrationColumns(), 'A re-import never wipes payments or check-ins');
    }

    public function testThePreviewReadsNothingFromTheRegistrationColumns(): void
    {
        $mapping = ColumnMapping::detect(['name', 'participant_id', ...CompetitionParticipantExporter::REGISTRATION_HEADERS], []);

        // Not mapped = "Not imported" on the preview (participant-import-preview.md D4)
        self::assertSame([0 => ParticipantImportField::Name, 1 => ParticipantImportField::ParticipantId], $mapping->fields);
    }

    /**
     * @return list<list<null|string>>
     */
    private function exportedRows(): array
    {
        $file = tempnam(sys_get_temp_dir(), 'test_export_');
        assert(is_string($file));
        file_put_contents($file, self::getContainer()->get(CompetitionParticipantExporter::class)->export(self::WJPC));
        $spreadsheet = IOFactory::load($file);
        unlink($file);

        /** @var list<list<null|string>> $rows */
        $rows = $spreadsheet->getActiveSheet()->toArray();

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registrationColumns(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT id, registration_status, registered_at, paid_at, checked_in_at, organizer_note FROM competition_participant WHERE competition_id = :id ORDER BY id',
            ['id' => self::WJPC],
        );

        return $rows;
    }

    /**
     * @param array<TranslatableMessage> $messages
     * @return list<string>
     */
    private function texts(array $messages): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        return array_values(array_map(static fn (TranslatableMessage $message): string => $message->trans($translator, 'en'), $messages));
    }
}
