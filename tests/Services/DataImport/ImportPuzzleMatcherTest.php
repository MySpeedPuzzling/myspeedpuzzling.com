<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\MatchImportPuzzlesFuzzy;
use SpeedPuzzling\Web\Message\ReadWaitingImports;
use SpeedPuzzling\Web\MessageHandler\BuildImportPuzzlesHandler;
use SpeedPuzzling\Web\MessageHandler\MatchImportPuzzlesFuzzyHandler;
use SpeedPuzzling\Web\Services\DataImport\ImportPuzzleMatcher;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The pairing of an import's distinct puzzles with the catalogue (docs/features/data-import/pairing.md): which tier
 * each rule gives, who is never a candidate, what "already yours" marks, and that the matcher's statements do not
 * grow with the import.
 */
final class ImportPuzzleMatcherTest extends KernelTestCase
{
    use ImportPairingScenario;
    use ClonesSolvingTimes;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testEveryExactRuleGivesItsTier(): void
    {
        $player = $this->seedPairingPlayer('tiers');
        $brand = $this->seedBrand('Zqbrand Pieces');
        $otherBrand = $this->seedBrand('Zqother');

        $barcodeOnTheBox = self::ean('299999100001');
        // A brand builds its barcode from its code: the box prints the code with the barcode's check digit after it
        $railwayBarcode = self::ean('299999154321');
        $byBarcode = $this->seedCataloguePuzzle('Zq Lighthouse at Dawn', 1000, $brand, eans: [$barcodeOnTheBox]);
        $byBrandCode = $this->seedCataloguePuzzle('Zq Mountain Railway', 500, $brand, eans: [$railwayBarcode], brandCodes: ['54321']);
        $byName = $this->seedCataloguePuzzle('Zq Where\'s the Ocean?', 500, $brand);
        $sameNameOtherBrand = $this->seedCataloguePuzzle('Zq Where\'s the Ocean?', 500, $otherBrand);
        $byOtherName = $this->seedCataloguePuzzle('Zq Magic Garden', 300, $brand, otherNames: new PuzzleNames([new PuzzleName('Zq Kouzelná zahrada', 'cs')]));
        $withoutBrand = $this->seedCataloguePuzzle('Zq Quiet Harbour', 1000, $otherBrand);
        $ownExport = $this->seedCataloguePuzzle('Zq Renamed Since', 2000, $brand);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'A box from the attic', 1000, null, ['barcodes' => [ltrim($barcodeOnTheBox, '0')]]);
        // The code as the box prints it: with the barcode's check digit after it
        $this->seedRow($import, $sheet, 'The railway one', 500, 'zqbrand  pieces', ['brandCodes' => ['54 321 ' . substr($railwayBarcode, -1)]]);
        // Case, an accent, the apostrophe, doubled spaces - and the brand written in one word
        $this->seedRow($import, $sheet, 'ZQ  WHERES THE OCÉAN?', 500, 'ZqbrandPieces');
        $this->seedRow($import, $sheet, 'zq kouzelna zahrada', 300, 'Zqbrand Pieces');
        $this->seedRow($import, $sheet, 'Zq Quiet Harbour', 1000);
        $this->seedRow($import, $sheet, 'Zq Quiet Harbour (gift)', 1000, 'Somebody Unknown');
        $this->seedRow($import, $sheet, 'Zq whatever it was called', 2000, null, ['puzzleId' => $ownExport]);
        $this->seedRow($import, $sheet, 'Zq Xylophone Qwertz Nobody Has', 500);

        $this->pair($import);

        $barcode = $this->importPuzzle($import, 'A box from the attic');
        self::assertSame(['found', 'catalogue', $byBarcode, 'automatic'], [$barcode['tier'], $barcode['choice'], $barcode['puzzle_id'], $barcode['decided_how']]);
        self::assertSame('barcode', $barcode['candidates'][0]['how']);
        self::assertNotNull($barcode['decided_at']);

        // A brand code is "probably": never decided for the player
        $brandCode = $this->importPuzzle($import, 'The railway one');
        self::assertSame(['probably', 'undecided', null, null], [$brandCode['tier'], $brandCode['choice'], $brandCode['puzzle_id'], $brandCode['decided_how']]);
        self::assertSame([$byBrandCode], self::candidateIds($brandCode));
        self::assertSame('brand_code', $brandCode['candidates'][0]['how']);
        self::assertSame($brand->id->toString(), $brandCode['manufacturer_id']);

        // Name + pieces + brand: the other brand's puzzle of the same name is a further candidate, not a doubt
        $name = $this->importPuzzle($import, 'ZQ  WHERES THE OCÉAN?');
        self::assertSame(['found', 'catalogue', $byName], [$name['tier'], $name['choice'], $name['puzzle_id']]);
        self::assertSame([$byName, $sameNameOtherBrand], self::candidateIds($name));

        $otherName = $this->importPuzzle($import, 'zq kouzelna zahrada');
        self::assertSame(['found', $byOtherName], [$otherName['tier'], $otherName['puzzle_id']]);

        // Name + pieces without a brand - or with a brand that is not the puzzle's - is "probably"
        foreach (['Zq Quiet Harbour', 'Zq Quiet Harbour (gift)'] as $written) {
            $probably = $this->importPuzzle($import, $written);
            self::assertSame(['probably', 'undecided', null], [$probably['tier'], $probably['choice'], $probably['puzzle_id']], $written);
            self::assertSame([$withoutBrand], self::candidateIds($probably), $written);
        }

        $ownId = $this->importPuzzle($import, 'Zq whatever it was called');
        self::assertSame(['found', $ownExport, 'own_id'], [$ownId['tier'], $ownId['puzzle_id'], $ownId['candidates'][0]['how']]);

        $nothing = $this->importPuzzle($import, 'Zq Xylophone Qwertz Nobody Has');
        self::assertSame(['none', 'undecided', []], [$nothing['tier'], $nothing['choice'], $nothing['candidates']]);

        $counters = $this->pairingCounters($import);
        self::assertEquals(['found' => 4, 'probably' => 3, 'choose' => 0, 'none' => 1, 'open' => 0], $counters['tiers']);
        self::assertSame(0, $counters['player']['decided']);
    }

    public function testTwoPuzzlesThatFitExactlyAreAChoice(): void
    {
        $player = $this->seedPairingPlayer('ambiguous');
        $brand = $this->seedBrand('Zqambi');
        $first = $this->seedCataloguePuzzle('Zq Twin Peaks Morning', 1000, $brand);
        $second = $this->seedCataloguePuzzle('Zq Twin Peaks Morning', 1000, $brand);
        $multipackCode = self::ean('299999200002');
        $multipackA = $this->seedCataloguePuzzle('Zq Pack One', 500, $brand, eans: [$multipackCode]);
        $multipackB = $this->seedCataloguePuzzle('Zq Pack Two', 500, $brand, eans: [$multipackCode]);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Twin Peaks Morning', 1000, 'Zqambi');
        $this->seedRow($import, $sheet, 'Zq a multipack', 500, null, ['barcodes' => [$multipackCode]]);
        $this->pair($import);

        $twins = $this->importPuzzle($import, 'Zq Twin Peaks Morning');
        self::assertSame(['choose', 'undecided', null], [$twins['tier'], $twins['choice'], $twins['puzzle_id']]);
        self::assertEqualsCanonicalizing([$first, $second], self::candidateIds($twins));

        $multipack = $this->importPuzzle($import, 'Zq a multipack');
        self::assertSame('choose', $multipack['tier']);
        self::assertEqualsCanonicalizing([$multipackA, $multipackB], self::candidateIds($multipack));
    }

    public function testACutListOfSameNamedPuzzlesIsNeverFound(): void
    {
        $player = $this->seedPairingPlayer('capped');
        $brand = $this->seedBrand('Zqcapped');
        $otherBrand = $this->seedBrand('Zqcappedother');
        $soloBrand = $this->seedBrand('Zqcappedsolo');
        $ofTheBrand = [];

        // 25 puzzles called "Zq Sunset", two of them of the file's brand - and one of a third brand
        for ($index = 0; $index < 25; $index++) {
            $id = $this->seedCataloguePuzzle('Zq Sunset', 1000, match (true) {
                $index === 7, $index === 23 => $brand,
                $index === 24 => $soloBrand,
                default => $otherBrand,
            });

            if ($index === 7 || $index === 23) {
                $ofTheBrand[] = $id;
            }
        }

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Sunset', 1000, 'Zqcapped');
        $this->seedRow($import, $sheet, 'zq sunset', 1000, 'Zqcappedsolo');
        $this->pair($import);

        // Both of the brand are among the candidates, first - whatever their ids
        $two = $this->importPuzzle($import, 'Zq Sunset');
        self::assertSame(['choose', 'undecided', null], [$two['tier'], $two['choice'], $two['puzzle_id']]);
        self::assertEqualsCanonicalizing($ofTheBrand, array_slice(self::candidateIds($two), 0, 2));

        // One of the brand among 25: the lookup was cut, so even the only strong one is not "found"
        $one = $this->importPuzzle($import, 'zq sunset');
        self::assertSame(['choose', 'undecided', null, null], [$one['tier'], $one['choice'], $one['puzzle_id'], $one['decided_how']]);
    }

    public function testANameWithoutItsBracketsOrTooShortIsOnlyASuggestion(): void
    {
        $player = $this->seedPairingPlayer('brackets');
        $brand = $this->seedBrand('Zqbrackets');
        $paris = $this->seedCataloguePuzzle('Zq Paris', 1000, $brand);
        $short = $this->seedCataloguePuzzle('Zqx', 500, $brand);
        $year = $this->seedCataloguePuzzle('20 26', 500, $brand);
        $shortWithBarcode = $this->seedCataloguePuzzle('Zqy', 500, $brand, eans: [self::ean('299999700007')]);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Paris (Panorama)', 1000, 'Zqbrackets');
        $this->seedRow($import, $sheet, 'ZQX', 500, 'Zqbrackets');
        $this->seedRow($import, $sheet, '20 26', 500, 'Zqbrackets');
        $this->seedRow($import, $sheet, 'Zqy', 500, 'Zqbrackets', ['barcodes' => [self::ean('299999700007')]]);
        $this->pair($import);

        foreach (['Zq Paris (Panorama)' => [$paris, 'name_without_brackets'], 'ZQX' => [$short, 'name_short'], '20 26' => [$year, 'name_short']] as $written => [$puzzle, $how]) {
            $importPuzzle = $this->importPuzzle($import, (string) $written);
            self::assertSame(['probably', 'undecided', null], [$importPuzzle['tier'], $importPuzzle['choice'], $importPuzzle['puzzle_id']], (string) $written);
            self::assertSame([$puzzle], self::candidateIds($importPuzzle), (string) $written);
            self::assertSame($how, $importPuzzle['candidates'][0]['how'], (string) $written);
        }

        // A barcode pairs whatever the name is
        self::assertSame(['found', $shortWithBarcode], [$this->importPuzzle($import, 'Zqy')['tier'], $this->importPuzzle($import, 'Zqy')['puzzle_id']]);
    }

    public function testAPairingThatDidNotComeToRestIsAskedForAgainABoundedNumberOfTimes(): void
    {
        $player = $this->seedPairingPlayer('safety-net');
        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Waiting For Its Pairing', 500);
        $importId = $import->id->toString();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $asked = static fn (): int => count(array_filter($transport->getSent(), static fn ($envelope): bool => $envelope->getMessage() == new BuildImportPuzzles($importId)));
        $rest = fn () => $this->pairingDatabase()->executeStatement(
            'UPDATE data_import SET last_activity_at = :at WHERE id = :id',
            ['at' => TestClock::now()->modify('-16 minutes')->format('Y-m-d H:i:s'), 'id' => $importId],
        );
        $this->pairingEntityManager()->clear();

        // Not parsed yet: nothing to pair
        $rest();
        $bus->dispatch(new ReadWaitingImports());
        self::assertSame(0, $asked());

        // Parsed, the pairing's run failed or its message was lost, a quarter of an hour without news
        $this->pairingDatabase()->executeStatement('UPDATE data_import_sheet SET mapping_version = 1, parsed_version = 1 WHERE id = :id', ['id' => $sheet->id->toString()]);
        $rest();
        $bus->dispatch(new ReadWaitingImports());
        self::assertSame(1, $asked());

        // Asked for a moment ago: left alone
        $bus->dispatch(new ReadWaitingImports());
        self::assertSame(1, $asked());

        // It fails every time: three times, no more
        for ($run = 0; $run < 4; $run++) {
            $rest();
            $bus->dispatch(new ReadWaitingImports());
        }

        self::assertSame(3, $asked());

        // A run that ends starts the count again, and a paired import is left alone
        $this->pair($importId);
        self::assertSame(0, $this->pairingCount('SELECT pairing_requeues FROM data_import WHERE id = :id', ['id' => $importId]));
        $transport->reset();
        $rest();
        $bus->dispatch(new ReadWaitingImports());
        self::assertSame(0, $asked());
    }

    public function testAHiddenPuzzleAndSomebodyElsesUnapprovedPuzzleAreNeverCandidates(): void
    {
        $player = $this->seedPairingPlayer('guards');
        $somebody = $this->seedPairingPlayer('somebody');
        $brand = $this->seedBrand('Zqguard');
        $future = TestClock::now()->modify('+30 days');

        $secretCode = self::ean('299999300003');
        $hidden = $this->seedCataloguePuzzle('Zq Secret Final Puzzle', 500, $brand, eans: [$secretCode], hideUntil: $future, hideImageUntil: $future);
        $imageHidden = $this->seedCataloguePuzzle('Zq Embargoed Picture', 500, $brand, hideImageUntil: $future);
        $somebodysNew = $this->seedCataloguePuzzle('Zq Just Added By Another', 500, $brand, approved: false, addedBy: $somebody);
        $ownNew = $this->seedCataloguePuzzle('Zq Just Added By Me', 500, $brand, approved: false, addedBy: $player);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Secret Final Puzzle', 500, 'Zqguard', ['barcodes' => [$secretCode], 'puzzleId' => $hidden]);
        $this->seedRow($import, $sheet, 'Zq Embargoed Picture', 500, 'Zqguard');
        $this->seedRow($import, $sheet, 'Zq Just Added By Another', 500, 'Zqguard');
        $this->seedRow($import, $sheet, 'Zq Just Added By Me', 500, 'Zqguard');
        $this->pair($import);

        foreach (['Zq Secret Final Puzzle' => $hidden, 'Zq Embargoed Picture' => $imageHidden, 'Zq Just Added By Another' => $somebodysNew] as $written => $never) {
            $importPuzzle = $this->importPuzzle($import, $written);
            self::assertNotContains($never, self::candidateIds($importPuzzle), $written);
            self::assertNull($importPuzzle['puzzle_id'], $written);
            self::assertNotSame('found', $importPuzzle['tier'], $written);
        }

        // By its name, its barcode and its own id - and not even the similar-name search offers it
        self::assertSame(['none', []], [$this->importPuzzle($import, 'Zq Secret Final Puzzle')['tier'], $this->importPuzzle($import, 'Zq Secret Final Puzzle')['candidates']]);

        $own = $this->importPuzzle($import, 'Zq Just Added By Me');
        self::assertSame(['found', $ownNew], [$own['tier'], $own['puzzle_id']]);
    }

    public function testAnotherPieceCountIsNeverMoreThanAChoice(): void
    {
        $player = $this->seedPairingPlayer('pieces');
        $brand = $this->seedBrand('Zqcount');
        $codeOfFiveHundred = self::ean('299999400004');
        $oddCode = self::ean('299999500005');
        $fiveHundred = $this->seedCataloguePuzzle('Zq Barcode Says Five Hundred', 500, $brand, eans: [$codeOfFiveHundred]);
        $thousand = $this->seedCataloguePuzzle('Zq Sunflower Field Panorama', 1000, $brand);
        $boxSays501 = $this->seedCataloguePuzzle('Zq Odd Box', 500, $brand, eans: [$oddCode]);

        [$import, $sheet] = $this->seedImport($player);
        // The barcode of one puzzle, the file says another piece count
        $this->seedRow($import, $sheet, 'Zq mine, 1000 pieces', 1000, null, ['barcodes' => [$codeOfFiveHundred]]);
        // The very name and brand of a catalogue puzzle, another piece count: only the similar-name search finds it
        $this->seedRow($import, $sheet, 'Zq Sunflower Field Panorama', 500, 'Zqcount');
        // One apart is the same count
        $this->seedRow($import, $sheet, 'Zq the odd one', 501, null, ['barcodes' => [$oddCode]]);
        $this->pair($import);

        $barcode = $this->importPuzzle($import, 'Zq mine, 1000 pieces');
        self::assertSame(['choose', 'undecided', null], [$barcode['tier'], $barcode['choice'], $barcode['puzzle_id']]);
        self::assertSame([$fiveHundred], self::candidateIds($barcode));
        self::assertTrue($barcode['candidates'][0]['piecesDiffer']);

        $name = $this->importPuzzle($import, 'Zq Sunflower Field Panorama');
        self::assertSame(['choose', 'undecided', null], [$name['tier'], $name['choice'], $name['puzzle_id']]);
        self::assertSame([$thousand], self::candidateIds($name));
        self::assertSame(['similar_name', true], [$name['candidates'][0]['how'], $name['candidates'][0]['piecesDiffer']]);

        $oneApart = $this->importPuzzle($import, 'Zq the odd one');
        self::assertSame(['found', $boxSays501], [$oneApart['tier'], $oneApart['puzzle_id']]);
    }

    public function testASimilarNameIsOfferedAndNeverDecided(): void
    {
        $player = $this->seedPairingPlayer('similar');
        $brand = $this->seedBrand('Zqsimilar');
        $catalogue = $this->seedCataloguePuzzle('Zq Circle of Colours: Ocean', 500, $brand);
        $this->seedCataloguePuzzle('Zq Completely Different Thing', 500, $brand);

        [$import, $sheet] = $this->seedImport($player);
        $this->seedRow($import, $sheet, 'Zq Circle of Colors Ocean', 500, 'Zqsimilar');
        $this->pair($import);

        $similar = $this->importPuzzle($import, 'Zq Circle of Colors Ocean');
        self::assertSame(['choose', 'undecided', null, null], [$similar['tier'], $similar['choice'], $similar['puzzle_id'], $similar['decided_how']]);
        self::assertSame([$catalogue], self::candidateIds($similar));
        self::assertSame('similar_name', $similar['candidates'][0]['how']);
        self::assertGreaterThanOrEqual(0.6, $similar['candidates'][0]['score']);
        self::assertFalse($similar['candidates'][0]['piecesDiffer']);
    }

    public function testAResultOnTheProfileMarksTheRowAndPairsItsPuzzle(): void
    {
        $player = $this->seedPairingPlayer('profile');
        $brand = $this->seedBrand('Zqprofile');
        $solved = $this->seedCataloguePuzzle('Zq Catalogue Name Of It', 1000, $brand);
        $roundTime = $this->seedCataloguePuzzle('Zq Another Catalogue Name', 1000, $brand);

        $result = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, [
            'player_id' => $player->id->toString(), 'puzzle_id' => $solved, 'seconds_to_solve' => 5437,
            'finished_at' => '2026-06-01 18:30:00', 'tracked_at' => '2026-06-01 18:30:00',
        ]);
        $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, [
            'player_id' => $player->id->toString(), 'puzzle_id' => $roundTime, 'seconds_to_solve' => 2700,
            'finished_at' => '2026-06-02 18:30:00', 'tracked_at' => '2026-06-02 18:30:00',
        ]);

        [$import, $sheet] = $this->seedImport($player);
        $met = $this->seedRow($import, $sheet, 'Zq how I call it at home', 1000, null, ['date' => '2026-06-01', 'datePrecision' => 'day', 'seconds' => 5437, 'type' => 'solo']);
        $otherDay = $this->seedRow($import, $sheet, 'Zq how I call it at home', 1000, null, ['date' => '2026-07-15', 'datePrecision' => 'day', 'seconds' => 5000, 'type' => 'solo']);
        // 45:00 is everybody's time: the row is on the profile, the puzzle is only "probably"
        $round = $this->seedRow($import, $sheet, 'Zq my other name', 1000, null, ['date' => '2026-06-02', 'datePrecision' => 'day', 'seconds' => 2700, 'type' => 'solo']);
        // The same time and day on a puzzle of another piece count is nothing
        $this->seedRow($import, $sheet, 'Zq a small one', 300, null, ['date' => '2026-06-01', 'datePrecision' => 'day', 'seconds' => 5437, 'type' => 'solo']);
        $this->pair($import);

        self::assertSame(
            ['already_yours_how' => 'profile', 'already_yours_solving_time_id' => $result, 'state' => 'waiting'],
            $this->pairingDatabase()->fetchAssociative('SELECT already_yours_how, already_yours_solving_time_id, state FROM data_import_row WHERE id = :id', ['id' => $met]),
        );
        self::assertNull($this->pairingDatabase()->fetchOne('SELECT already_yours_how FROM data_import_row WHERE id = :id', ['id' => $otherDay]));
        self::assertSame('profile', $this->pairingDatabase()->fetchOne('SELECT already_yours_how FROM data_import_row WHERE id = :id', ['id' => $round]));

        $home = $this->importPuzzle($import, 'Zq how I call it at home');
        self::assertSame(['found', $solved, 'own_result', 2], [$home['tier'], $home['puzzle_id'], $home['candidates'][0]['how'], $home['rows_results']]);

        $other = $this->importPuzzle($import, 'Zq my other name');
        self::assertSame(['probably', null, [$roundTime]], [$other['tier'], $other['puzzle_id'], self::candidateIds($other)]);

        self::assertSame('none', $this->importPuzzle($import, 'Zq a small one')['tier']);
    }

    public function testARowAnEarlierImportAddedIsAlreadyYours(): void
    {
        $player = $this->seedPairingPlayer('earlier');
        $stranger = $this->seedPairingPlayer('stranger');
        $puzzle = $this->seedCataloguePuzzle('Zq Landed Here Last Time', 1000);
        $key = '1:' . hash('sha256', 'the same data in another file');

        $result = $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, ['player_id' => $player->id->toString(), 'puzzle_id' => $puzzle, 'seconds_to_solve' => 4000]);
        $gone = Uuid::uuid7()->toString();

        [$first, $firstSheet] = $this->seedImport($player);
        $added = $this->seedRow($first, $firstSheet, 'Zq my spelling of it', 1000, sourceKey: $key);
        $undone = $this->seedRow($first, $firstSheet, 'Zq undone since', 1000, sourceKey: '1:' . hash('sha256', 'undone'));
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET solving_time_id = :result, state = 'added' WHERE id = :id", ['result' => $result, 'id' => $added]);
        $this->pairingDatabase()->executeStatement("UPDATE data_import_row SET solving_time_id = :result, state = 'added' WHERE id = :id", ['result' => $gone, 'id' => $undone]);

        // Somebody else's import with the very same key decides nothing
        [$theirs, $theirSheet] = $this->seedImport($stranger);
        $theirRow = $this->seedRow($theirs, $theirSheet, 'Zq my spelling of it', 1000, sourceKey: $key);

        [$second, $secondSheet] = $this->seedImport($player);
        $again = $this->seedRow($second, $secondSheet, 'Zq my spelling of it', 1000, sourceKey: $key);
        $undoneAgain = $this->seedRow($second, $secondSheet, 'Zq undone since', 1000, sourceKey: '1:' . hash('sha256', 'undone'));
        $this->pair($second);
        $this->pair($theirs);

        self::assertSame(
            ['already_yours_how' => 'earlier_import', 'already_yours_solving_time_id' => $result],
            $this->pairingDatabase()->fetchAssociative('SELECT already_yours_how, already_yours_solving_time_id FROM data_import_row WHERE id = :id', ['id' => $again]),
        );
        // A result that is gone is not "already yours"
        self::assertNull($this->pairingDatabase()->fetchOne('SELECT already_yours_how FROM data_import_row WHERE id = :id', ['id' => $undoneAgain]));
        self::assertNull($this->pairingDatabase()->fetchOne('SELECT already_yours_how FROM data_import_row WHERE id = :id', ['id' => $theirRow]));

        $paired = $this->importPuzzle($second, 'Zq my spelling of it');
        self::assertSame(['found', $puzzle, 'earlier_import'], [$paired['tier'], $paired['puzzle_id'], $paired['candidates'][0]['how']]);
        self::assertSame('none', $this->importPuzzle($theirs, 'Zq my spelling of it')['tier']);
    }

    public function testRowsAreCountedByKindAndLinkedToTheirPuzzle(): void
    {
        $player = $this->seedPairingPlayer('counts');
        [$import, $sheet] = $this->seedImport($player);
        $a = $this->seedRow($import, $sheet, 'Zq Counted', 500, 'Zqnobrand');
        $this->seedRow($import, $sheet, 'zq  COUNTED', 500, 'zq-nobrand', kind: DataImportRowKind::Both);
        $this->seedRow($import, $sheet, 'Zq Counted', 500, 'Zqnobrand', kind: DataImportRowKind::Owned);
        $notAPuzzle = $this->seedRow($import, $sheet, 'Zq Total', kind: DataImportRowKind::NotAPuzzle);
        $this->pair($import);

        $counted = $this->importPuzzle($import, 'Zq Counted');
        self::assertSame([2, 2], [$counted['rows_results'], $counted['rows_owned']]);
        self::assertSame($counted['id'], $this->pairingDatabase()->fetchOne('SELECT import_puzzle_id FROM data_import_row WHERE id = :id', ['id' => $a]));
        self::assertNull($this->pairingDatabase()->fetchOne('SELECT import_puzzle_id FROM data_import_row WHERE id = :id', ['id' => $notAPuzzle]));
        self::assertSame(1, $this->pairingCount('SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id', ['id' => $import->id->toString()]));
    }

    public function testTheSimilarNameSearchGoesOnInChunksUntilNothingWaits(): void
    {
        $player = $this->seedPairingPlayer('chunks');
        $catalogue = $this->seedCataloguePuzzle('Zq Chunked Lavender Fields', 500);
        [$import, $sheet] = $this->seedImport($player);

        for ($index = 0; $index < ImportPuzzleMatcher::SEARCH_CHUNK + 5; $index++) {
            $this->seedRow($import, $sheet, $index === 3 ? 'Zq Chunked Lavender Field' : 'Zq ' . md5('chunk-' . $index), 1000);
        }

        $transport = self::getContainer()->get('messenger.transport.imports');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->pair($import);

        // The build asked for the search, the first (full) chunk asked for the next
        self::assertEquals(
            [new MatchImportPuzzlesFuzzy($import->id->toString(), 1), new MatchImportPuzzlesFuzzy($import->id->toString(), 1)],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
        self::assertSame(
            ['choose' => 1, 'none' => ImportPuzzleMatcher::SEARCH_CHUNK + 4],
            $this->pairingDatabase()->fetchAllKeyValue('SELECT tier, COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND searched_at IS NOT NULL GROUP BY tier ORDER BY tier', ['id' => $import->id->toString()]),
        );
        self::assertSame([$catalogue], self::candidateIds($this->importPuzzle($import, 'Zq Chunked Lavender Field')));

        // A search of an older run finds another run number and does nothing
        $this->pairingDatabase()->executeStatement('UPDATE data_import_puzzle SET tier = NULL, searched_at = NULL WHERE import_id = :id', ['id' => $import->id->toString()]);
        $this->handled(self::getContainer()->get(MatchImportPuzzlesFuzzyHandler::class), new MatchImportPuzzlesFuzzy($import->id->toString(), 0));
        self::assertSame(0, $this->pairingCount('SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND tier IS NOT NULL', ['id' => $import->id->toString()]));
    }

    public function testTheMatchersStatementsDoNotGrowWithTheImport(): void
    {
        $player = $this->seedPairingPlayer('budget');
        $brand = $this->seedBrand('Zqbudget');
        $this->seedCataloguePuzzle('Zq Budget Puzzle', 500, $brand, eans: [self::ean('299999600006')]);

        $small = $this->seedImportOfPuzzles($player, 2);
        $large = $this->seedImportOfPuzzles($player, 60);

        $statementsOfSmall = $this->statementsOfABuild($small, 2);
        $statementsOfLarge = $this->statementsOfABuild($large, 60);

        self::assertGreaterThan(0, $statementsOfSmall);
        self::assertSame($statementsOfSmall, $statementsOfLarge, 'Building and pairing 60 puzzles must cost the statements of 2.');
    }

    private function seedImportOfPuzzles(Player $player, int $puzzles): string
    {
        [$import, $sheet] = $this->seedImport($player);

        for ($index = 0; $index < $puzzles; $index++) {
            $this->seedRow($import, $sheet, $index === 0 ? 'Zq Budget Puzzle' : 'Zq Budget Unknown ' . $index, 500, 'Zqbudget', $index === 1 ? ['barcodes' => [self::ean('299999600006')]] : []);
        }

        return $import->id->toString();
    }

    private function statementsOfABuild(string $importId, int $puzzles): int
    {
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $this->handled(self::getContainer()->get(BuildImportPuzzlesHandler::class), new BuildImportPuzzles($importId));

        $statements = 0;

        foreach ($holder->getData() as $queries) {
            $statements += is_array($queries) ? count($queries) : 0;
        }

        self::assertSame($puzzles, $this->pairingCount('SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id', ['id' => $importId]));
        self::assertSame(2, $this->pairingCount("SELECT COUNT(*) FROM data_import_puzzle WHERE import_id = :id AND tier = 'found'", ['id' => $importId]));
        $this->pairingEntityManager()->clear();

        return $statements;
    }
}
