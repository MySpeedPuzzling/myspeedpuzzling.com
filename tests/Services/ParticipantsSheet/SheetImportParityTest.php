<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\ParticipantsSheet;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Services\ParticipantImport\Plan\ParticipantImportOperations;
use SpeedPuzzling\Web\Services\ParticipantsSheet\SheetChangesParser;
use SpeedPuzzling\Web\Services\ParticipantsSheet\SheetChangesPlanner;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as Cup;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use SpeedPuzzling\Web\Value\ParticipantSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A file and a sheet never disagree (participants-spreadsheet.md §6 "Implementation seam"): the same change written as
 * an import row and as sheet changes plans the same operations - the rules (ParticipantRules, the results guard read by
 * SiteSnapshotReader) are one.
 */
final class SheetImportParityTest extends KernelTestCase
{
    private ParticipantImportPlanner $importPlanner;
    private SheetChangesPlanner $sheetPlanner;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->importPlanner = self::getContainer()->get(ParticipantImportPlanner::class);
        $this->sheetPlanner = self::getContainer()->get(SheetChangesPlanner::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAPersonChangedAndPutIntoRoundsAndAPair(): void
    {
        $lateBirds = $this->team(Cup::ROUND_PAIRS_FINAL, 'Late Birds');

        $import = $this->importOperations(new ParticipantImportRows([
            new ParticipantImportRowData(
                rowNumber: 2,
                name: 'Ivan Lastly',
                country: 'cz',
                playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                participantId: Cup::PARTICIPANT_IVAN,
                roundNames: 'Group A, Pairs Final',
                teamsByRound: [Cup::ROUND_PAIRS_FINAL => 'Late Birds'],
            ),
        ], roundsMapped: true), ParticipantImportMode::Update);

        $sheet = $this->sheetOperations([
            ['op' => 'field', 'participant' => Cup::PARTICIPANT_IVAN, 'field' => 'name', 'from' => 'Ivan Last', 'to' => 'Ivan Lastly'],
            ['op' => 'field', 'participant' => Cup::PARTICIPANT_IVAN, 'field' => 'country', 'from' => 'sk', 'to' => 'cz'],
            ['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_WITH_FAVORITES],
            ['op' => 'place', 'participant' => Cup::PARTICIPANT_IVAN, 'round' => Cup::ROUND_GROUP_B, 'from' => 'in', 'to' => 'in'],
            ['op' => 'place', 'participant' => Cup::PARTICIPANT_IVAN, 'round' => Cup::ROUND_GROUP_A, 'from' => 'out', 'to' => 'in'],
            ['op' => 'place', 'participant' => Cup::PARTICIPANT_IVAN, 'round' => Cup::ROUND_PAIRS_FINAL, 'from' => 'out', 'to' => 'team:' . $lateBirds],
        ]);

        self::assertNotSame([], $import['participants']);
        self::assertCount(2, $import['newEntries']);
        self::assertSame($import, $sheet);
    }

    public function testAProfileLinkedToSomebodyElseIsNeverLinkedTwice(): void
    {
        // Gina holds PLAYER_PRIVATE - the file's row keeps Ivan unlinked (a row message), the sheet refuses the change
        $import = $this->importOperations(new ParticipantImportRows([
            new ParticipantImportRowData(rowNumber: 2, name: 'Ivan Last', country: 'sk', playerId: PlayerFixture::PLAYER_PRIVATE, participantId: Cup::PARTICIPANT_IVAN),
        ]), ParticipantImportMode::Update);

        $sheet = $this->sheetOperations(
            [['op' => 'field', 'participant' => Cup::PARTICIPANT_IVAN, 'field' => 'name', 'from' => 'Ivan Last', 'to' => 'Ivan Last']],
            ['op' => 'player', 'participant' => Cup::PARTICIPANT_IVAN, 'from' => null, 'to' => PlayerFixture::PLAYER_PRIVATE],
        );

        self::assertSame(self::empty(), $import);
        self::assertSame($import, $sheet);
    }

    public function testRemovingPeopleFollowsTheSameRules(): void
    {
        $selfJoined = $this->participant('Self Joiner', ParticipantSource::SelfJoined);

        // Full sync with everybody but Filip, the self-joined person and Ben (who holds results - kept by both)
        $rows = [];
        $number = 2;
        foreach ([Cup::PARTICIPANT_ANNA, Cup::PARTICIPANT_CARA, Cup::PARTICIPANT_DAN, Cup::PARTICIPANT_EVA, Cup::PARTICIPANT_GINA, Cup::PARTICIPANT_HUGO, Cup::PARTICIPANT_IVAN] as $participantId) {
            $participant = $this->entityManager->find(CompetitionParticipant::class, $participantId);
            assert($participant !== null);
            $rows[] = new ParticipantImportRowData(rowNumber: $number++, name: $participant->name, country: $participant->country, participantId: $participantId);
        }

        $import = $this->importOperations(new ParticipantImportRows($rows), ParticipantImportMode::Sync);

        $sheet = $this->sheetOperations([
            ['op' => 'remove', 'participant' => Cup::PARTICIPANT_FILIP],
            ['op' => 'remove', 'participant' => $selfJoined],
        ], ['op' => 'remove', 'participant' => Cup::PARTICIPANT_BEN]);

        self::assertCount(2, $import['participants']);
        self::assertSame($import, $sheet);
    }

    /**
     * @return array{participants: list<array<string, mixed>>, newEntries: list<array<string, mixed>>, entryTeams: list<array<string, mixed>>, deletedEntries: list<string>, deletedTeams: list<string>}
     */
    private function importOperations(ParticipantImportRows $rows, ParticipantImportMode $mode): array
    {
        $operations = $this->importPlanner->plan(Cup::COMPETITION_RESULTS_CUP, $rows, $mode)->operations;
        assert($operations instanceof ParticipantImportOperations);

        return self::comparable($operations);
    }

    /**
     * Every list of changes is one group - and each further list (here: changes the rules refuse) another one.
     *
     * @param list<array<string, mixed>> $changes
     * @param array<string, mixed> ...$refused
     * @return array{participants: list<array<string, mixed>>, newEntries: list<array<string, mixed>>, entryTeams: list<array<string, mixed>>, deletedEntries: list<string>, deletedTeams: list<string>}
     */
    private function sheetOperations(array $changes, array ...$refused): array
    {
        $groups = [['id' => 'parity', 'changes' => $changes]];
        foreach ($refused as $number => $change) {
            $groups[] = ['id' => 'refused-' . $number, 'changes' => [$change]];
        }

        $parsed = SheetChangesParser::parse(['changesetId' => Uuid::uuid7()->toString(), 'groups' => $groups]);
        $plan = $this->sheetPlanner->plan(Cup::COMPETITION_RESULTS_CUP, false, $parsed['groups'], 'version');

        self::assertContains($plan->groups[0]->status->value, ['applied', 'unchanged'], 'The sheet applies the changes the file makes');
        foreach (array_slice($plan->groups, 1) as $group) {
            self::assertSame('refused', $group->status->value);
        }

        return self::comparable($plan->operations);
    }

    /**
     * What both write: the import's keys of every operation (the sheet adds its own optional ones), in a fixed order.
     *
     * @return array{participants: list<array<string, mixed>>, newEntries: list<array<string, mixed>>, entryTeams: list<array<string, mixed>>, deletedEntries: list<string>, deletedTeams: list<string>}
     */
    private static function comparable(ParticipantImportOperations $operations): array
    {
        $participants = array_map(static fn (array $operation): array => [
            'key' => $operation['key'],
            'name' => $operation['name'],
            'country' => $operation['country'],
            'externalId' => $operation['externalId'],
            'connectPlayerId' => $operation['connectPlayerId'],
            'markAsImported' => $operation['markAsImported'],
            'restore' => $operation['restore'],
            'softDelete' => $operation['softDelete'],
        ], $operations->participants);
        $newEntries = $operations->newEntries;
        $entryTeams = $operations->entryTeams;
        $deletedEntries = $operations->deletedEntries;
        $deletedTeams = $operations->deletedTeams;

        sort($participants);
        sort($newEntries);
        sort($entryTeams);
        sort($deletedEntries);
        sort($deletedTeams);

        return [
            'participants' => $participants,
            'newEntries' => $newEntries,
            'entryTeams' => $entryTeams,
            'deletedEntries' => $deletedEntries,
            'deletedTeams' => $deletedTeams,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function empty(): array
    {
        return ['participants' => [], 'newEntries' => [], 'entryTeams' => [], 'deletedEntries' => [], 'deletedTeams' => []];
    }

    private function team(string $roundId, string $name): string
    {
        $round = $this->entityManager->find(CompetitionRound::class, $roundId);
        assert($round !== null);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, $name);
        $this->entityManager->persist($team);
        $this->entityManager->flush();

        return $team->id->toString();
    }

    private function participant(string $name, ParticipantSource $source): string
    {
        $competition = $this->entityManager->find(Competition::class, Cup::COMPETITION_RESULTS_CUP);
        assert($competition !== null);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, null, $competition, $source);
        $this->entityManager->persist($participant);
        $this->entityManager->flush();

        return $participant->id->toString();
    }
}
