<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ImportPersonChoiceRefused;
use SpeedPuzzling\Web\Message\ApplyImportRow;
use SpeedPuzzling\Web\Message\DecideImportPerson;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\LeaveImportedResults;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Query\GetImportPeople;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
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
use Symfony\Component\Messenger\Stamp\HandledStamp;
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
        // The page, the review's card and the engine agree: a guest
        self::assertSame(0, self::getContainer()->get(GetImportPeople::class)->counts($import->id->toString(), $importer->id->toString())['players']);
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

        // "Ben" is kept as a guest by the player's own word
        [$guestImport, $guestSheet] = $this->importOf($importer);
        $this->resultRow($guestImport, $guestSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-04-01', 5900, ['type' => 'duo', 'people' => ['Ben Zqguest']]);
        $this->pair($guestImport);
        $this->decide($guestImport->id->toString(), $importer, $this->peopleOf($guestImport->id->toString(), $importer)[0]->personId, ImportPersonChoice::Guest);

        // The next file names both again. The remembered PLAYER is offered, never applied: the name is a guest for
        // adding until the player says "still right" in this import. The remembered GUEST applies silently
        [$next, $nextSheet] = $this->importOf($importer);
        $offeredRow = $this->resultRow($next, $nextSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-05-01', 6000, ['type' => 'duo', 'people' => ['ANA ZQNOVAK']]);
        $this->resultRow($next, $nextSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-05-02', 6100, ['type' => 'duo', 'people' => ['ANA ZQNOVAK']]);
        $this->resultRow($next, $nextSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-05-03', 6200, ['type' => 'duo', 'people' => ['ben zqguest']]);
        $this->pair($next);
        $nextId = $next->id->toString();
        [$ana, $ben] = $this->peopleOf($nextId, $importer);
        self::assertSame(['guest', 'default', null, 'player', $friend->id->toString()], [$ana->choice->value, $ana->decidedHow->value, $ana->playerId, $ana->rememberedChoice?->value, $ana->rememberedPlayerId]);
        self::assertSame(['guest', 'remembered', null], [$ben->choice->value, $ben->decidedHow->value, $ben->rememberedChoice]);
        self::assertSame(['people' => 2, 'players' => 0, 'unanswered' => 1], self::getContainer()->get(GetImportPeople::class)->counts($nextId, $importer->id->toString()));

        // Added before the answer: a guest
        $this->addAndWork($next, $importer, [$offeredRow]);
        self::assertSame([], $this->registeredPartnersOf($this->rowNow($offeredRow)['solving_time_id'], $importer));

        // "Yes, still right" - one tap, the player's word in this import
        $this->pairingEntityManager()->clear();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DecideImportPerson($nextId, $importer->id->toString(), $ana->personId, ImportPersonChoice::Guest, asRemembered: true));
        $this->pairingEntityManager()->clear();
        $confirmed = $this->peopleOf($nextId, $importer)[0];
        self::assertSame(['player', 'player', $friend->id->toString(), null], [$confirmed->choice->value, $confirmed->decidedHow->value, $confirmed->playerId, $confirmed->rememberedChoice]);
        $this->addAndWork($next, $importer);
        // The friend is on the first file's result and on the row added after the answer - not on the one added before
        self::assertSame(2, $this->resultsWith($friend));

        // "Yes for all remembered" in a further file; a remembered player who blocks since is not offered at all
        [$third, $thirdSheet] = $this->importOf($importer);
        $this->resultRow($third, $thirdSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-06-01', 6300, ['type' => 'duo', 'people' => ['Ana Zqnovak']]);
        $this->pair($third);
        $thirdId = $third->id->toString();
        self::assertSame('player', $this->peopleOf($thirdId, $importer)[0]->rememberedChoice?->value);
        $this->pairingEntityManager()->clear();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new DecideImportPerson($thirdId, $importer->id->toString(), '', ImportPersonChoice::Guest, allRemembered: true));
        $this->pairingEntityManager()->clear();
        self::assertSame(['player', $friend->id->toString()], [$this->peopleOf($thirdId, $importer)[0]->choice->value, $this->peopleOf($thirdId, $importer)[0]->playerId]);

        $this->block($friend, $importer);
        [$fourth, $fourthSheet] = $this->importOf($importer);
        $this->resultRow($fourth, $fourthSheet, 'Zq Map Meadow', 1000, 'Zqmap', '2024-07-01', 6400, ['type' => 'duo', 'people' => ['Ana Zqnovak']]);
        $this->pair($fourth);
        $blocked = $this->peopleOf($fourth->id->toString(), $importer)[0];
        self::assertSame(['guest', null, null], [$blocked->choice->value, $blocked->rememberedChoice, $blocked->rememberedPlayerId]);
        self::assertSame(ImportPersonChoiceRefused::UNKNOWN_PLAYER, $this->decideRefused($fourth->id->toString(), $importer, $blocked->personId, asRemembered: true));
    }

    public function testTheImportersOwnNameIsAskedNeverAssumedAndAPairNeverBecomesSoloOnAnAssumption(): void
    {
        $importer = $this->named($this->seedPairingPlayer('self'), 'Zq Selfie');
        $brand = $this->seedBrand('Zqself');

        foreach (['Harbour', 'Meadow', 'Castle', 'Tower', 'Bridge', 'Garden'] as $name) {
            $this->seedCataloguePuzzle('Zq Self ' . $name, 1000, $brand);
        }

        [$import, $sheet] = $this->importOf($importer);
        // "Zq Selfie & Ana": two names, a team of three as the parser counts - the importing player may have a
        // namesake, so nothing is assumed: a team with two guests
        $byName = $this->resultRow($import, $sheet, 'Zq Self Harbour', 1000, 'Zqself', '2024-03-01', 5400, ['type' => 'team', 'people' => ['zq  selfie', 'Ana']]);
        // Their own CODE is them, as in the add form: left out - a pair with Ben
        $byCode = $this->resultRow($import, $sheet, 'Zq Self Meadow', 1000, 'Zqself', '2024-03-02', 5500, ['type' => 'team', 'people' => ['#' . $importer->code, 'Ben']]);
        // Only their own name: a pair with a guest of that name - never a solo result
        $alone = $this->resultRow($import, $sheet, 'Zq Self Castle', 1000, 'Zqself', '2024-03-03', 5600, ['type' => 'duo', 'people' => ['Zq Selfie']]);
        // Only their own code: a pair that names nobody else - asked, like the add form refuses a group of oneself
        $codeAlone = $this->resultRow($import, $sheet, 'Zq Self Tower', 1000, 'Zqself', '2024-03-04', 5700, ['type' => 'duo', 'people' => ['#' . $importer->code]]);
        $this->pair($import);

        $people = [];

        foreach ($this->peopleOf($import->id->toString(), $importer) as $person) {
            $people[$person->personKey] = [$person->choice->value, $person->decidedHow->value, $person->isOwnName];
        }

        self::assertSame(['guest', 'default', true], $people['g:zq selfie']);
        self::assertSame(['me', 'code', false], $people['#' . $importer->code]);
        self::assertSame(['guest', 'default', false], $people['g:ana']);

        $this->addAndWork($import, $importer);

        self::assertSame(['added', 'added', 'added'], [$this->rowNow($byName)['state'], $this->rowNow($byCode)['state'], $this->rowNow($alone)['state']]);
        self::assertSame(['team', 'duo', 'duo'], array_column($this->resultsOf($importer), 'puzzling_type'));
        self::assertSame(['waiting', ImportRowRefusal::PeopleDoNotFitType->value], [$this->rowNow($codeAlone)['state'], $this->rowNow($codeAlone)['failure']]);

        // "Yes, that is me" - the player's own answer: left out, and with nobody else the row is the solo result it is
        [$second, $secondSheet] = $this->importOf($importer);
        $pairRow = $this->resultRow($second, $secondSheet, 'Zq Self Bridge', 1000, 'Zqself', '2024-04-01', 5800, ['type' => 'team', 'people' => ['Zq Selfie', 'Ana']]);
        $soloRow = $this->resultRow($second, $secondSheet, 'Zq Self Garden', 1000, 'Zqself', '2024-04-02', 5900, ['type' => 'duo', 'people' => ['Zq Selfie']]);
        $this->pair($second);
        $secondId = $second->id->toString();
        $own = array_values(array_filter($this->peopleOf($secondId, $importer), static fn (ImportPersonCard $person): bool => $person->personKey === 'g:zq selfie'))[0];
        // The earlier files said nothing about the name: asked again, nothing remembered, nothing assumed
        self::assertSame([true, null, 'guest'], [$own->isOwnName, $own->rememberedChoice, $own->choice->value]);
        $this->decide($secondId, $importer, $own->personId, ImportPersonChoice::Me);
        $this->addAndWork($second, $importer);

        self::assertSame(['duo', 'solo'], [$this->resultsOf($importer, null)[3]['puzzling_type'], $this->resultsOf($importer, null)[4]['puzzling_type']]);
        self::assertSame(['added', 'added'], [$this->rowNow($pairRow)['state'], $this->rowNow($soloRow)['state']]);

        // Remembered "me" is offered in the next file - not applied
        [$third, $thirdSheet] = $this->importOf($importer);
        $this->resultRow($third, $thirdSheet, 'Zq Self Tower', 1000, 'Zqself', '2024-05-01', 6000, ['type' => 'duo', 'people' => ['Zq Selfie']]);
        $this->pair($third);
        $offered = $this->peopleOf($third->id->toString(), $importer)[0];
        self::assertSame(['guest', 'default', 'me'], [$offered->choice->value, $offered->decidedHow->value, $offered->rememberedChoice?->value]);
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

    public function testUndoGivesNoRoomBackTheDailyLimitsCountWhatWasMade(): void
    {
        $importer = $this->seedPairingPlayer('churn');
        $partner = $this->seedPairingPlayer('churned');
        $brand = $this->seedBrand('Zqchurn');
        $this->seedCataloguePuzzle('Zq Churn Harbour', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $day = new \DateTimeImmutable('2020-01-01');

        for ($index = 0; $index < DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY; $index++) {
            $this->resultRow($import, $sheet, 'Zq Churn Harbour', 1000, 'Zqchurn', $day->modify('+' . $index . ' days')->format('Y-m-d'), 5400 + $index * 7, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        }

        $this->pair($import);
        $outcome = $this->addAndWork($import, $importer);
        self::assertSame(DataImportLimits::MAX_RESULTS_PER_PARTNER_PER_DAY, $this->resultsWith($partner));
        $guard = self::getContainer()->get(DataImportRowRepository::class);
        self::assertSame(200, $guard->countResultsAddedOrQueuedSince($importer->id->toString(), TestClock::now()));

        // Add 200, undo, add again: the partner's profile is not churned - the day's 200 are used up
        $this->undo($import, $importer, DataImportUndoScope::Batch, $outcome->id);
        self::assertSame(0, $this->resultsWith($partner));
        self::assertSame(200, $guard->countResultsAddedOrQueuedSince($importer->id->toString(), TestClock::now()));

        $again = $this->addAndWork($import, $importer);
        self::assertSame(0, $this->resultsWith($partner));
        self::assertSame([0, 200], [$this->batchNow($again->id)['rows_added'], $this->batchNow($again->id)['rows_refused']]);
        self::assertSame(200, $this->pairingCount('SELECT COUNT(*) FROM data_import_row WHERE import_id = :id AND failure = :why', ['id' => $import->id->toString(), 'why' => ImportRowRefusal::PartnerDailyLimit->value]));

        // The importing player's own 2,000 a day are counted the same way: used up, an "Add" takes no result
        $this->pairingDatabase()->executeStatement('UPDATE data_import_daily_count SET results = :limit WHERE importer_id = :id AND partner_id = :id', ['limit' => DataImportLimits::MAX_RESULTS_ADDED_PER_DAY, 'id' => $importer->id->toString()]);
        [$solo, $soloSheet] = $this->importOf($importer);
        $this->resultRow($solo, $soloSheet, 'Zq Churn Harbour', 1000, 'Zqchurn', '2024-03-01', 9000);
        $this->pair($solo);
        $refused = $this->add($solo, $importer);
        self::assertSame([0, ['over_daily_limit' => 1]], [$refused->rows, $refused->skipped]);
    }

    public function testNoPlayerGetsMoreThanFiveHundredImportedResultsADayFromEverybodyTogether(): void
    {
        $importer = $this->seedPairingPlayer('lastone');
        $partner = $this->seedPairingPlayer('popular');
        $brand = $this->seedBrand('Zqpop');
        $this->seedCataloguePuzzle('Zq Pop Harbour', 1000, $brand);

        // Three other importing players already put 499 results on the partner today
        foreach ([200, 200, 99] as $index => $results) {
            $other = $this->seedPairingPlayer('popother' . $index);
            $this->pairingDatabase()->insert('data_import_daily_count', ['id' => Uuid::uuid7()->toString(), 'importer_id' => $other->id->toString(), 'partner_id' => $partner->id->toString(), 'day' => substr(TestClock::FROZEN_NOW, 0, 10), 'results' => $results]);
        }

        [$import, $sheet] = $this->importOf($importer);
        $first = $this->resultRow($import, $sheet, 'Zq Pop Harbour', 1000, 'Zqpop', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $second = $this->resultRow($import, $sheet, 'Zq Pop Harbour', 1000, 'Zqpop', '2024-03-02', 5500, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($import);
        $this->addAndWork($import, $importer);

        self::assertSame('added', $this->rowNow($first)['state']);
        self::assertSame(['waiting', ImportRowRefusal::PartnerDailyLimit->value], [$this->rowNow($second)['state'], $this->rowNow($second)['failure']]);
    }

    public function testAPartnerIsToldOncePerImporterAndDayAndUnlessTheyBlockTheImporter(): void
    {
        $importer = $this->named($this->seedPairingPlayer('daily'), 'Zq Daily');
        $partner = $this->seedPairingPlayer('dailypartner');
        $blocker = $this->seedPairingPlayer('dailyblocker');
        $brand = $this->seedBrand('Zqdaily');
        $this->seedCataloguePuzzle('Zq Daily Harbour', 1000, $brand);

        [$import, $sheet] = $this->importOf($importer);
        $rows = [];

        foreach ([$partner, $partner, $partner, $blocker] as $index => $with) {
            $rows[] = $this->resultRow($import, $sheet, 'Zq Daily Harbour', 1000, 'Zqdaily', '2024-03-0' . ($index + 1), 5400 + $index * 11, ['type' => 'duo', 'people' => ['#' . $with->code]]);
        }

        $this->pair($import);

        // Every press of "Add" is a batch: two batches the same day
        $this->addAndWork($import, $importer, [$rows[0]]);
        $notifications = self::getContainer()->get(GetNotifications::class);
        self::assertSame([1], array_map(static fn ($told): int => $told->importRowsAdded, $notifications->forPlayer($partner->id->toString(), 10, 0)));
        $this->pairingDatabase()->executeStatement('UPDATE notification SET read_at = notified_at WHERE player_id = :id', ['id' => $partner->id->toString()]);

        // The importing player's own block does not silence it (and stops nothing that was resolved before only
        // through the write-time check: here the block comes after the rows were written). Whoever blocks the
        // importing player is told nothing
        $this->add($import, $importer, [$rows[1], $rows[2], $rows[3]]);
        $batchId = $this->pairingDatabase()->fetchOne('SELECT id FROM data_import_batch WHERE import_id = :id ORDER BY started_at DESC, id DESC LIMIT 1', ['id' => $import->id->toString()]);
        self::assertIsString($batchId);

        foreach ([$rows[1], $rows[2], $rows[3]] as $rowId) {
            self::getContainer()->get(MessageBusInterface::class)->dispatch(new ApplyImportRow($rowId, $batchId, $importer->id->toString()));
            $this->pairingEntityManager()->clear();
        }

        self::assertSame(['added', 'added', 'added'], [$this->rowNow($rows[1])['state'], $this->rowNow($rows[2])['state'], $this->rowNow($rows[3])['state']]);
        $this->block($importer, $partner);
        $this->block($blocker, $importer);
        $this->work($batchId);

        // Still ONE notification for the day - moved, unread again, with the day's count
        $told = $notifications->forPlayer($partner->id->toString(), 10, 0);
        self::assertSame([3], array_map(static fn ($one): int => $one->importRowsAdded, $told));
        self::assertNull($told[0]->readAt);
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $partner->id->toString()]));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM notification WHERE player_id = :id', ['id' => $blocker->id->toString()]));
    }

    public function testAPartnerTakesThemselfOffTheImportedResultsAndIsNotResolvedByThatImporterAgain(): void
    {
        $importer = $this->seedPairingPlayer('pushy');
        $partner = $this->named($this->seedPairingPlayer('leaver'), 'Zq Leaver');
        $brand = $this->seedBrand('Zqleaver');
        $typedPuzzle = $this->seedCataloguePuzzle('Zq Leaver Typed', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Leaver Harbour', 1000, $brand);
        $this->seedCataloguePuzzle('Zq Leaver Meadow', 1000, $brand);
        // A result the two typed together is theirs and stays
        $typed = $this->typedResult($importer, $typedPuzzle, '2023-01-01', 7000, ['#' . $partner->code]);

        [$import, $sheet] = $this->importOf($importer);
        $this->resultRow($import, $sheet, 'Zq Leaver Harbour', 1000, 'Zqleaver', '2024-03-01', 5400, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->resultRow($import, $sheet, 'Zq Leaver Harbour', 1000, 'Zqleaver', '2024-03-02', 5500, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($import);
        $this->addAndWork($import, $importer);
        self::assertSame(3, $this->resultsWith($partner));

        $this->async->reset();
        $this->pairingEntityManager()->clear();
        $left = self::getContainer()->get(MessageBusInterface::class)->dispatch(new LeaveImportedResults($partner->id->toString(), $importer->id->toString()))->last(HandledStamp::class)?->getResult();
        $this->pairingEntityManager()->clear();

        self::assertSame(2, $left);
        // The results keep existing, tracked by the importing player - with a guest of the partner's name
        $results = $this->resultsOf($importer);
        self::assertCount(3, $results);
        self::assertSame([$typed], array_values(array_filter(array_column($results, 'id'), fn (mixed $id): bool => $this->registeredPartnersOf($id, $importer) === [$partner->id->toString()])));
        self::assertSame(1, $this->resultsWith($partner));
        self::assertSame(2, $this->pairingCount("SELECT COUNT(*) FROM puzzle_solving_time WHERE player_id = :id AND created_via = 'spreadsheet_import' AND (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_name', 'Zq Leaver'))", ['id' => $importer->id->toString()]));
        // Achievements once for each of the two, however many results
        self::assertEqualsCanonicalizing([$partner->id->toString(), $importer->id->toString()], $this->badgeJobs());

        // From now on this importing player's files do not find them: a guest, like a code nobody has - on the page,
        // for a typed code, and in the result
        [$next, $nextSheet] = $this->importOf($importer);
        $row = $this->resultRow($next, $nextSheet, 'Zq Leaver Meadow', 1000, 'Zqleaver', '2024-04-01', 5600, ['type' => 'duo', 'people' => ['#' . $partner->code]]);
        $this->pair($next);
        $person = $this->peopleOf($next->id->toString(), $importer)[0];
        self::assertSame(['guest', 'default', null], [$person->choice->value, $person->decidedHow->value, $person->playerId]);
        self::assertSame(ImportPersonChoiceRefused::UNKNOWN_PLAYER, $this->decideRefused($next->id->toString(), $importer, $person->personId, code: $partner->code));
        $this->addAndWork($next, $importer);
        self::assertSame([], $this->registeredPartnersOf($this->rowNow($row)['solving_time_id'], $importer));
        self::assertSame(1, $this->resultsWith($partner));

        // Pressed again: nothing left to do
        self::assertSame(0, self::getContainer()->get(MessageBusInterface::class)->dispatch(new LeaveImportedResults($partner->id->toString(), $importer->id->toString()))->last(HandledStamp::class)?->getResult());
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM import_partner_opt_out WHERE player_id = :id', ['id' => $partner->id->toString()]));
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

    private function decideRefused(string $importId, Player $importer, string $personId, null|string $targetPlayerId = null, null|string $code = null, bool $asRemembered = false): null|string
    {
        try {
            if ($asRemembered) {
                $this->pairingEntityManager()->clear();
                self::getContainer()->get(MessageBusInterface::class)->dispatch(new DecideImportPerson($importId, $importer->id->toString(), $personId, ImportPersonChoice::Guest, asRemembered: true));

                return null;
            }

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
