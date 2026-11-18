<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\ParticipantClaimLinks;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ApplyParticipantImport;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use SpeedPuzzling\Web\Value\ColumnMapping;
use SpeedPuzzling\Web\Value\ParticipantImportField;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The organiser's address of a participant (docs/features/competitions-management/participant-linking.md D15, §7.1,
 * §7.3): the import's Email column (set when filled, canonical; an empty cell keeps it, a wrong one is reported) and the
 * participants sheet's E-mail column (three-way, validated, empty clears).
 */
final class ParticipantEmailTest extends KernelTestCase
{
    use SheetChangeSets;

    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheImportsColumnIsFoundByItsUsualNames(): void
    {
        foreach (['email', 'E-mail', 'Mail', 'e_mail address'] as $header) {
            self::assertSame([0 => ParticipantImportField::Name, 1 => ParticipantImportField::Email], ColumnMapping::detect(['name', $header], [])->fields, $header);
        }
    }

    public function testTheImportSetsTheAddressCanonicalAnEmptyCellKeepsItAndAWrongOneIsReported(): void
    {
        $this->database->executeStatement("UPDATE competition_participant SET email = 'john@example.test' WHERE id = :id", ['id' => Wjpc::PARTICIPANT_CONNECTED]);

        $rows = new ParticipantImportRows([
            new ParticipantImportRowData(rowNumber: 2, name: 'Jane Unconnected', email: '  Jane.U@Example.TEST '),
            new ParticipantImportRowData(rowNumber: 3, name: 'John Regular', email: ''),
            new ParticipantImportRowData(rowNumber: 4, name: 'Newcomer Nina', email: 'nina at example'),
        ]);
        $planner = self::getContainer()->get(ParticipantImportPlanner::class);
        $plan = $planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $rows, ParticipantImportMode::Update);

        self::assertSame(['Row 4: "nina at example" is not an e-mail address - the row is imported without it.'], $this->texts($plan->rows[2]->messages));
        self::assertTrue($plan->canBeApplied());

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ApplyParticipantImport(CompetitionFixture::COMPETITION_WJPC_2024, $rows, ParticipantImportMode::Update->value, $plan->fingerprint));

        self::assertSame('jane.u@example.test', $this->emailOf(Wjpc::PARTICIPANT_UNCONNECTED));
        self::assertSame('john@example.test', $this->emailOf(Wjpc::PARTICIPANT_CONNECTED), 'An empty cell never clears');
        self::assertNull($this->database->fetchOne("SELECT email FROM competition_participant WHERE name = 'Newcomer Nina'"));
    }

    public function testTheSheetSetsChecksAndClearsTheAddress(): void
    {
        $applied = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'email', null, ' Ivan@Example.TEST '))]);

        self::assertSame(['applied'], self::groupStatuses($applied));
        self::assertSame('ivan@example.test', $this->emailOf(Cup::PARTICIPANT_IVAN));

        // Families share an address - allowed
        $shared = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_HUGO, 'email', null, 'ivan@example.test'))]);
        self::assertSame(['applied'], self::groupStatuses($shared));

        // Not an address
        $refused = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'email', 'ivan@example.test', 'ivan@'))]);
        self::assertSame(['refused:invalid_email'], self::changeStatuses($refused));
        self::assertSame('participant_claim_invitation.sheet.reason.invalid_email', $refused->groups[0]->changes[0]->messageKey());

        // From a value nobody holds any more: a conflict; the right one in another spelling: through
        $conflict = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'email', 'old@example.test', 'new@example.test'))]);
        self::assertSame(['conflict:changed_meanwhile'], self::changeStatuses($conflict));

        $cleared = $this->applySheetChanges([self::sheetGroup(self::fieldChange(Cup::PARTICIPANT_IVAN, 'email', 'IVAN@example.test', ''))]);
        self::assertSame(['applied'], self::groupStatuses($cleared));
        self::assertNull($this->emailOf(Cup::PARTICIPANT_IVAN));
    }

    private function emailOf(string $participantId): mixed
    {
        $email = $this->database->fetchOne('SELECT email FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return $email === false ? null : $email;
    }

    /**
     * @param list<TranslatableMessage> $messages
     * @return list<string>
     */
    private function texts(array $messages): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        return array_values(array_map(static fn (TranslatableMessage $message): string => $message->trans($translator, 'en'), $messages));
    }
}
