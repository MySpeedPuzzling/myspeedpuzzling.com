<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ImportPersonChoiceRefused;
use SpeedPuzzling\Web\Message\DecideImportPerson;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Query\GetImportPeople;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Results\ImportPersonCard;
use SpeedPuzzling\Web\Results\ImportPersonSuggestion;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportUndoScope;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The people of a file (docs/features/data-import/people.md): a player's code in the file becomes that player, a
 * name stays a guest unless the importing player says who it is - and the registered partner is told once, never
 * flooded, and gets what a typed result gives them.
 */
final class ImportPeopleTest extends KernelTestCase
{
    use ImportAddingScenario;

    private InMemoryTransport $async;

    protected function setUp(): void
    {
        self::bootKernel();
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $this->async = $async;
    }

    public function testACodeBecomesThePlayerWhoHasTheResultAndIsToldOncePerBatch(): void
    {
        $importer = $this->named($this->seedPairingPlayer('importer'), 'Zq Importer');
        $partner = $this->named($this->seedPairingPlayer('partner'), 'Zq Partner');
        $brand = $this->seedBrand('Zqppl');
        $first = $this->seedCataloguePuzzle('Zq People Harbour', 1000, $brand);
        $second = $this->seedCataloguePuzzle('Zq People Meadow', 1000, $brand);
        $third = $this->seedCataloguePuzzle('Zq People Castle', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        // The code as people write it: with the sign, in capitals
        $this->resultRow($import, $sheet, 'Zq People Harbour', 1000, 'Zqppl', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . strtoupper($partner->code)]]);
        $this->resultRow($import, $sheet, 'Zq People Meadow', 1000, 'Zqppl', '2024-03-02', 5500, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->resultRow($import, $sheet, 'Zq People Castle', 1000, 'Zqppl', '2024-03-03', 5600, ['type' => 'duo', 'people' => ['Ana']]);
        $this->pair($import);

        // Built with the puzzles: the code resolved by itself, the name is a guest nobody decided anything about
        $people = $this->peopleOf($import->id->toString(), $importer);
        self::assertSame(
            [['#' . $partner->code, 2, 'player', 'code', $partner->id->toString()], ['g:ana', 1, 'guest', 'default', null]],
            array_map(static fn (ImportPersonCard $person): array => [$person->personKey, $person->rowsCount, $person->choice->value, $person->decidedHow->value, $person->playerId], $people),
        );

        $outcome = $this->addAndWork($import, $importer);

        $results = $this->resultsOf($importer);
        self::assertCount(3, $results);
        self::assertSame([$partner->id->toString()], $this->registeredPartnersOf($results[0]['id'], $importer));
        self::assertSame([$partner->id->toString()], $this->registeredPartnersOf($results[1]['id'], $importer));
        self::assertSame([], $this->registeredPartnersOf($results[2]['id'], $importer));
        // The partner has the two results - on their profile like a typed pair result
        self::assertSame(2, $this->resultsWith($partner));
        self::assertSame([$first, $second, $third], array_column($results, 'puzzle_id'));

        // ONE notification for the batch, whatever the number of results - and the importing player's own
        self::assertSame(
            [NotificationType::ImportResultsAddedWithYou->value],
            $this->pairingDatabase()->fetchFirstColumn('SELECT type FROM notification WHERE player_id = :id', ['id' => $partner->id->toString()]),
        );
        self::assertSame(2, $this->pairingCount('SELECT CAST(partner_results ->> :partner AS INTEGER) FROM data_import_batch WHERE id = :id', ['partner' => $partner->id->toString(), 'id' => $outcome->id]));

        $told = self::getContainer()->get(GetNotifications::class)->forPlayer($partner->id->toString(), 10, 0);
        self::assertCount(1, $told);
        self::assertTrue($told[0]->isImportPartnerNotification());
        self::assertFalse($told[0]->isImportNotification());
        // The partner's own count, who added them - and nothing of the import itself
        self::assertSame([2, $importer->id->toString(), 'Zq Importer', null, null], [$told[0]->importRowsAdded, $told[0]->targetPlayerId, $told[0]->targetPlayerName, $told[0]->importId, $told[0]->importFileName]);

        // Achievements: the importing player and the partner, once each
        self::assertEqualsCanonicalizing([$importer->id->toString(), $partner->id->toString()], $this->badgeJobs());

        // Undo: the results are gone for both, the partner is recalculated once - not once per result - and what
        // they were told stays (a deleted group result tells its other people nothing)
        $this->async->reset();
        $this->undo($import, $importer, DataImportUndoScope::Batch, $outcome->id);

        self::assertSame([], $this->resultsOf($importer));
        self::assertSame(0, $this->resultsWith($partner));
        self::assertEqualsCanonicalizing([$importer->id->toString(), $partner->id->toString()], $this->badgeJobs());
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $partner->id->toString()]));
    }

    public function testACodeNobodyHasAndAPlayerWithABlockEitherWayAreGuestsAlike(): void
    {
        $importer = $this->seedPairingPlayer('careful');
        $blocksImporter = $this->seedPairingPlayer('blocker');
        $blockedByImporter = $this->seedPairingPlayer('blocked');
        $this->block($blocksImporter, $importer);
        $this->block($importer, $blockedByImporter);
        $brand = $this->seedBrand('Zqblk');

        [$import, $sheet] = $this->importOf($importer);

        foreach (['#' . $blocksImporter->code, '#' . $blockedByImporter->code, '#zz9zz9'] as $index => $person) {
            $this->seedCataloguePuzzle('Zq Block ' . $index, 1000, $brand);
            $this->resultRow($import, $sheet, 'Zq Block ' . $index, 1000, 'Zqblk', '2024-04-0' . ($index + 1), 5000 + $index, ['type' => 'duo', 'people' => [$person]]);
        }

        $this->pair($import);

        // The page can not tell them apart: three guests nobody decided anything about, nobody offered
        foreach ($this->peopleOf($import->id->toString(), $importer) as $person) {
            self::assertSame(['guest', 'default', null, []], [$person->choice->value, $person->decidedHow->value, $person->playerId, $person->suggestions], $person->name);
        }

        // Typed again as a code on the page: the same refusal for the three
        foreach ($this->peopleOf($import->id->toString(), $importer) as $person) {
            self::assertSame(ImportPersonChoiceRefused::UNKNOWN_PLAYER, $this->decideRefused($import->id->toString(), $importer, $person->personId, code: $person->name), $person->name);
        }

        $this->addAndWork($import, $importer);

        $results = $this->resultsOf($importer);
        self::assertCount(3, $results);

        foreach ($results as $result) {
            self::assertSame([], $this->registeredPartnersOf($result['id'], $importer));
        }

        self::assertEqualsCanonicalizing(
            [$blocksImporter->code, $blockedByImporter->code, 'zz9zz9'],
            $this->pairingDatabase()->fetchFirstColumn('SELECT guest_name FROM puzzling_team_member WHERE player_id IS NULL AND team_id IN (SELECT puzzling_team_id FROM puzzle_solving_time WHERE player_id = :id)', ['id' => $importer->id->toString()]),
        );
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id IN (:a, :b)', ['a' => $blocksImporter->id->toString(), 'b' => $blockedByImporter->id->toString()]));
    }

    public function testABlockThatCameAfterTheCodeResolvedStillMakesAGuest(): void
    {
        $importer = $this->seedPairingPlayer('late');
        $partner = $this->seedPairingPlayer('latepartner');
        $brand = $this->seedBrand('Zqlate');
        $this->seedCataloguePuzzle('Zq Late Harbour', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $this->resultRow($import, $sheet, 'Zq Late Harbour', 1000, 'Zqlate', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($import);
        self::assertSame('player', $this->peopleOf($import->id->toString(), $importer)[0]->choice->value);

        $this->block($partner, $importer);
        // The page and the engine agree: a guest
        self::assertSame(['guest', null], [$this->peopleOf($import->id->toString(), $importer)[0]->choice->value, $this->peopleOf($import->id->toString(), $importer)[0]->playerId]);

        $this->addAndWork($import, $importer);

        self::assertSame([], $this->registeredPartnersOf($this->resultsOf($importer)[0]['id'], $importer));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $partner->id->toString()]));
    }

    public function testANameIsAGuestUntilTheImporterSaysItIsOneOfTheirOwnPeople(): void
    {
        $importer = $this->named($this->seedPairingPlayer('mapper'), 'Zq Mapper');
        $friend = $this->named($this->seedPairingPlayer('friend'), 'Ána  Zqnovak');
        $maybe = $this->named($this->seedPairingPlayer('maybe'), 'Ana Zqother');
        $stranger = $this->named($this->seedPairingPlayer('stranger'), 'Ana Zqnovak');
        $privateFriend = $this->named($this->seedPairingPlayer('private'), 'Ana Zqnovak');
        $blockedFriend = $this->named($this->seedPairingPlayer('blockedfriend'), 'Ana Zqnovak');
        $this->pairingDatabase()->update('player', ['is_private' => 'true'], ['id' => $privateFriend->id->toString()]);
        $this->favourites($importer, [$friend, $maybe, $privateFriend, $blockedFriend]);
        $this->block($blockedFriend, $importer);
        $brand = $this->seedBrand('Zqmap');
        $this->seedCataloguePuzzle('Zq Map Harbour', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Map Meadow', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $this->resultRow($import, $sheet, 'Zq Map Harbour', 1000, 'Zqmap', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['Ana Zqnovak']]);
        $this->pair($import);
        $importId = $import->id->toString();

        $person = $this->peopleOf($importId, $importer)[0];
        self::assertSame(['guest', 'default'], [$person->choice->value, $person->decidedHow->value]);
        // Only the importing player's own people: the favourite of that name (exact, accents and spaces folded), the
        // favourite who shares the first name (maybe) - never the stranger of the same name, the private player who
        // has not let them in, or the one with a block
        self::assertSame(
            [[$friend->id->toString(), true], [$maybe->id->toString(), false]],
            array_map(static fn (ImportPersonSuggestion $suggestion): array => [$suggestion->playerId, $suggestion->exact], $person->suggestions),
        );

        // A player who was not offered can not be posted by id
        self::assertSame(ImportPersonChoiceRefused::UNKNOWN_PLAYER, $this->decideRefused($importId, $importer, $person->personId, targetPlayerId: $stranger->id->toString()));
        self::assertSame(ImportPersonChoiceRefused::UNKNOWN_PLAYER, $this->decideRefused($importId, $importer, $person->personId, targetPlayerId: $privateFriend->id->toString()));
        self::assertSame('guest', $this->peopleOf($importId, $importer)[0]->choice->value);

        // On the allow list the private player is offered too
        $this->pairingDatabase()->insert('private_profile_viewer', ['id' => Uuid::uuid7()->toString(), 'added_at' => TestClock::FROZEN_NOW, 'owner_id' => $privateFriend->id->toString(), 'viewer_id' => $importer->id->toString()]);
        self::assertContains($privateFriend->id->toString(), array_map(static fn (ImportPersonSuggestion $suggestion): string => $suggestion->playerId, $this->peopleOf($importId, $importer)[0]->suggestions));

        $this->decide($importId, $importer, $person->personId, ImportPersonChoice::Player, targetPlayerId: $friend->id->toString());
        $decided = $this->peopleOf($importId, $importer)[0];
        self::assertSame(['player', 'player', $friend->id->toString()], [$decided->choice->value, $decided->decidedHow->value, $decided->playerId]);

        $this->addAndWork($import, $importer);
        self::assertSame([$friend->id->toString()], $this->registeredPartnersOf($this->resultsOf($importer)[0]['id'], $importer));

        // The answer is remembered for the same name in the next file - shown as remembered, and changeable
        [$next, $nextSheet] = $this->importOf($importer);
        $this->resultRow($next, $nextSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-05-01', 6000, ['type' => 'duo', 'people' => ['ANA ZQNOVAK']]);
        $this->pair($next);
        $remembered = $this->peopleOf($next->id->toString(), $importer)[0];
        self::assertSame(['player', 'remembered', $friend->id->toString(), true], [$remembered->choice->value, $remembered->decidedHow->value, $remembered->playerId, $remembered->isRemembered()]);

        $this->decide($next->id->toString(), $importer, $remembered->personId, ImportPersonChoice::Guest);
        $this->addAndWork($next, $importer);
        self::assertSame([], $this->registeredPartnersOf($this->resultsOf($importer, null)[1]['id'], $importer));
    }

    public function testTheImportersOwnNameAndCodeAreLeftOutOfThePartners(): void
    {
        $importer = $this->named($this->seedPairingPlayer('self'), 'Zq Selfie');
        $brand = $this->seedBrand('Zqself');
        $this->seedCataloguePuzzle('Zq Self Harbour', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Self Meadow', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Self Castle', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        // "Me & Ana" is two names - the parser counts a team of three; without "me" it is the pair it was
        $byName = $this->resultRow($import, $sheet, 'Zq Self Harbour', 1000, 'Zqself', '2024-03-01', 5400, ['type' => 'team', 'people' => ['zq  selfie', 'Ana']]);
        $byCode = $this->resultRow($import, $sheet, 'Zq Self Meadow', 1000, 'Zqself', '2024-03-02', 5500, ['type' => 'team', 'people' => ['#' . $importer->code, 'Ben']]);
        // Only "me": the solo result it is
        $alone = $this->resultRow($import, $sheet, 'Zq Self Castle', 1000, 'Zqself', '2024-03-03', 5600, ['type' => 'duo', 'people' => ['Zq Selfie']]);
        $this->pair($import);

        self::assertEqualsCanonicalizing(
            [['me', 'own_name'], ['me', 'code'], ['guest', 'default'], ['guest', 'default']],
            array_map(static fn (ImportPersonCard $person): array => [$person->choice->value, $person->decidedHow->value], $this->peopleOf($import->id->toString(), $importer)),
        );

        $this->addAndWork($import, $importer);

        self::assertSame(['added', 'added', 'added'], [$this->rowNow($byName)['state'], $this->rowNow($byCode)['state'], $this->rowNow($alone)['state']]);
        self::assertSame(['duo', 'duo', 'solo'], array_column($this->resultsOf($importer), 'puzzling_type'));
    }

    public function testAResultThePartnerAlreadyTrackedIsNeverASecondResult(): void
    {
        $importer = $this->seedPairingPlayer('second');
        $partner = $this->seedPairingPlayer('first');
        $brand = $this->seedBrand('Zqtwice');
        $together = $this->seedCataloguePuzzle('Zq Twice Harbour', 1000, $brand);
        $apart = $this->seedCataloguePuzzle('Zq Twice Meadow', 1000, $brand);
        // The partner logged both evenings: one with the importing player on it, one with them as a guest only
        $shared = $this->typedResult($partner, $together, '2024-03-01', 5400, ['#' . $importer->code]);
        $this->typedResult($partner, $apart, '2024-03-02', 5500, ['Somebody']);

        [$import, $sheet] = $this->importOf($importer);
        $sharedRow = $this->resultRow($import, $sheet, 'Zq Twice Harbour', 1000, 'Zqtwice', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $apartRow = $this->resultRow($import, $sheet, 'Zq Twice Meadow', 1000, 'Zqtwice', '2024-03-02', 5500, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($import);
        $this->addAndWork($import, $importer);

        // The importing player is on the partner's result: already theirs, linked - no second result
        self::assertSame(['already_yours', 'profile', $shared], [$this->rowNow($sharedRow)['state'], $this->rowNow($sharedRow)['already_yours_how'], $this->rowNow($sharedRow)['already_yours_solving_time_id']]);
        // Not on it: asked, never added by itself
        self::assertSame(['waiting', ImportRowRefusal::SomebodyElsesResult->value], [$this->rowNow($apartRow)['state'], $this->rowNow($apartRow)['failure']]);
        self::assertSame([], $this->resultsOf($importer));
        self::assertSame(2, $this->pairingCount('SELECT COUNT(*) FROM puzzle_solving_time WHERE puzzle_id IN (:a, :b)', ['a' => $together, 'b' => $apart]));
    }

    public function testAPartnersFirstTryIsRespected(): void
    {
        $importer = $this->seedPairingPlayer('eager');
        $partner = $this->seedPairingPlayer('holder');
        $brand = $this->seedBrand('Zqfirst');
        $puzzle = $this->seedCataloguePuzzle('Zq First Harbour', 1000, $brand);
        $this->typedResult($partner, $puzzle, '2023-01-01', 7200, firstTry: true);

        [$import, $sheet] = $this->importOf($importer);
        $row = $this->resultRow($import, $sheet, 'Zq First Harbour', 1000, 'Zqfirst', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code], 'firstTry' => true]);
        $this->pair($import);
        $this->addAndWork($import, $importer);

        self::assertSame(['waiting', ImportedResultRefusal::FirstTryTaken->value], [$this->rowNow($row)['state'], $this->rowNow($row)['failure']]);
        self::assertSame([], $this->resultsOf($importer));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $partner->id->toString()]));
    }

    public function testOneAddPutsResultsOnAtMostTwentyRegisteredPartners(): void
    {
        $importer = $this->seedPairingPlayer('crowd');
        $brand = $this->seedBrand('Zqcrowd');
        $this->seedCataloguePuzzle('Zq Crowd Harbour', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $rows = [];

        for ($index = 0; $index <= DataImportLimits::MAX_PARTNERS_PER_BATCH; $index++) {
            $partner = $this->seedPairingPlayer('crowd' . $index);
            $rows[] = $this->resultRow($import, $sheet, 'Zq Crowd Harbour', 1000, 'Zqcrowd', sprintf('2024-03-%02d', $index + 1), 5400 + $index * 7, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        }

        $this->pair($import);
        $this->addAndWork($import, $importer);

        self::assertCount(DataImportLimits::MAX_PARTNERS_PER_BATCH, $this->resultsOf($importer));
        $last = $this->rowNow($rows[DataImportLimits::MAX_PARTNERS_PER_BATCH]);
        self::assertSame(['waiting', ImportRowRefusal::TooManyPartners->value], [$last['state'], $last['failure']]);
        self::assertSame(DataImportLimits::MAX_PARTNERS_PER_BATCH, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE type = :type AND actor_player_id = :id', ['type' => NotificationType::ImportResultsAddedWithYou->value, 'id' => $importer->id->toString()]));
    }

    public function testAPartnerGetsAtMostTwoHundredResultsADayFromOneImporter(): void
    {
        $importer = $this->seedPairingPlayer('flood');
        $partner = $this->seedPairingPlayer('flooded');
        $brand = $this->seedBrand('Zqflood');
        $this->seedCataloguePuzzle('Zq Flood Harbour', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Flood Meadow', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $day = new \DateTimeImmutable('2020-01-01');

        for ($index = 0; $index < DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY + 2; $index++) {
            $last = $this->resultRow($import, $sheet, 'Zq Flood Harbour', 1000, 'Zqflood', $day->modify('+' . $index . ' days')->format('Y-m-d'), 5400 + $index * 7, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        }

        // A row with somebody else is not held back by the partner's limit
        $other = $this->resultRow($import, $sheet, 'Zq Flood Meadow', 1000, 'Zqflood', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['Ana']]);
        $this->pair($import);
        $outcome = $this->addAndWork($import, $importer);

        self::assertSame(DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY, $this->resultsWith($partner));
        self::assertSame(['waiting', ImportRowRefusal::PartnerDailyLimit->value], [$this->rowNow($last)['state'], $this->rowNow($last)['failure']]);
        self::assertSame(2, $this->batchNow($outcome->id)['rows_refused']);
        self::assertSame('added', $this->rowNow($other)['state']);
        // Still one notification
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $partner->id->toString()]));
    }

    public function testDeletingTheImportersAccountTreatsAnImportedGroupResultLikeATypedOne(): void
    {
        $importer = $this->seedPairingPlayer('leaving');
        $partner = $this->seedPairingPlayer('staying');
        $brand = $this->seedBrand('Zqleave');
        $typedPuzzle = $this->seedCataloguePuzzle('Zq Leave Harbour', 1000, $brand);
        $importedPuzzle = $this->seedCataloguePuzzle('Zq Leave Meadow', 1000, $brand);
        $this->typedResult($importer, $typedPuzzle, '2024-03-01', 5400, ['#' . $partner->code]);

        [$import, $sheet] = $this->importOf($importer);
        $this->resultRow($import, $sheet, 'Zq Leave Meadow', 1000, 'Zqleave', '2024-03-02', 5500, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($import);
        $this->addAndWork($import, $importer);
        self::assertSame(2, $this->resultsWith($partner));

        $this->pairingEntityManager()->clear();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DeletePlayer($importer->id->toString()));
        $this->pairingEntityManager()->clear();

        // Whatever the deletion does with a group result its tracker leaves - both results end the same way
        $after = $this->pairingDatabase()->fetchAllAssociative(
            'SELECT puzzle_id, player_id, puzzling_type FROM puzzle_solving_time WHERE puzzle_id IN (:typed, :imported) ORDER BY puzzle_id',
            ['typed' => $typedPuzzle, 'imported' => $importedPuzzle],
        );
        $byPuzzle = array_column($after, null, 'puzzle_id');
        self::assertSame(isset($byPuzzle[$typedPuzzle]), isset($byPuzzle[$importedPuzzle]));

        if (isset($byPuzzle[$typedPuzzle], $byPuzzle[$importedPuzzle])) {
            self::assertSame([$byPuzzle[$typedPuzzle]['player_id'], $byPuzzle[$typedPuzzle]['puzzling_type']], [$byPuzzle[$importedPuzzle]['player_id'], $byPuzzle[$importedPuzzle]['puzzling_type']]);
        }

        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_person WHERE import_id = :id', ['id' => $import->id->toString()]));
    }

    private function named(Player $player, string $name): Player
    {
        $this->pairingDatabase()->update('player', ['name' => $name], ['id' => $player->id->toString()]);
        $this->pairingEntityManager()->clear();
        $managed = $this->pairingEntityManager()->find(Player::class, $player->id->toString());
        self::assertNotNull($managed);

        return $managed;
    }

    private function block(Player $blocker, Player $blocked): void
    {
        $this->pairingDatabase()->insert('user_block', [
            'id' => Uuid::uuid7()->toString(),
            'blocker_id' => $blocker->id->toString(),
            'blocked_id' => $blocked->id->toString(),
            'blocked_at' => TestClock::FROZEN_NOW,
            'source' => 'self',
        ]);
    }

    /**
     * @param list<Player> $favourites
     */
    private function favourites(Player $player, array $favourites): void
    {
        $this->pairingDatabase()->update(
            'player',
            ['favorite_players' => json_encode(array_map(static fn (Player $favourite): string => $favourite->id->toString(), $favourites), JSON_THROW_ON_ERROR)],
            ['id' => $player->id->toString()],
        );
        $this->pairingEntityManager()->clear();
    }

    /**
     * @return list<ImportPersonCard>
     */
    private function peopleOf(string $importId, Player $importer): array
    {
        return self::getContainer()->get(GetImportPeople::class)->ofImport($importId, $importer->id->toString());
    }

    private function decide(string $importId, Player $importer, string $personId, ImportPersonChoice $choice, null|string $targetPlayerId = null, null|string $code = null): void
    {
        $this->pairingEntityManager()->clear();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DecideImportPerson($importId, $importer->id->toString(), $personId, $choice, $targetPlayerId, $code));
        $this->pairingEntityManager()->clear();
    }

    private function decideRefused(string $importId, Player $importer, string $personId, null|string $targetPlayerId = null, null|string $code = null): null|string
    {
        try {
            $this->decide($importId, $importer, $personId, ImportPersonChoice::Player, $targetPlayerId, $code);
        } catch (HandlerFailedException $exception) {
            $this->pairingEntityManager()->clear();
            $cause = $exception->getPrevious();

            return $cause instanceof ImportPersonChoiceRefused ? $cause->reason : throw $exception;
        }

        return null;
    }

    /**
     * The registered people of a result besides the importing player.
     *
     * @return list<string>
     */
    private function registeredPartnersOf(mixed $solvingTimeId, Player $importer): array
    {
        /** @var list<string> $ids */
        $ids = $this->pairingDatabase()->fetchFirstColumn(
            'SELECT member.player_id FROM puzzling_team_member member WHERE member.team_id = (SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id) AND member.player_id IS NOT NULL AND member.player_id <> :importer ORDER BY member.player_id',
            ['id' => $solvingTimeId, 'importer' => $importer->id->toString()],
        );

        return $ids;
    }

    /**
     * Results the player is a registered person of - what their profile shows.
     */
    private function resultsWith(Player $player): int
    {
        return $this->pairingCount(
            "SELECT COUNT(*) FROM puzzle_solving_time WHERE (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:id AS UUID)))",
            ['id' => $player->id->toString()],
        );
    }

    /**
     * @return list<string> the players whose achievements were queued for a recalculation
     */
    private function badgeJobs(): array
    {
        $ids = [];

        foreach ($this->async->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof RecalculateBadgesForPlayer) {
                $ids[] = $message->playerId;
            }
        }

        return $ids;
    }
}
