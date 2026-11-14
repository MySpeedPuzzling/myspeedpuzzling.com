<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCustomValues;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Message\CopyPuzzlesToCollection;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Message\MovePuzzlesToCollection;
use SpeedPuzzling\Web\Message\MovePuzzleToCollection;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromCollection;
use SpeedPuzzling\Web\Message\SetCollectionItemCustomValues;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\CustomValueProblem;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The owner's own values on the puzzles of their collection (docs/features/custom-fields.md "Collection items"):
 * written by the owner alone, into their own fields for collection puzzles, and kept when an item is copied, moved
 * or meets another item of the same puzzle.
 *
 * Fixtures: PLAYER_WITH_STRIPE owns COLLECTION_PUBLIC (500_01, 500_02, 500_04, 500_05, 1000_01, 1000_03, 1000_05,
 * 300) and COLLECTION_STRIPE_TREFL (1000_04, 500_02, 1000_05).
 */
final class CollectionItemCustomValuesTest extends KernelTestCase
{
    private const string OWNER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string SOMEBODY_ELSE = PlayerFixture::PLAYER_REGULAR;
    private const string ITEM = CollectionItemFixture::ITEM_01;
    private const string SOURCE = CollectionFixture::COLLECTION_PUBLIC;
    private const string TARGET = CollectionFixture::COLLECTION_STRIPE_TREFL;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private CustomFieldsScenario $scenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new CustomFieldsScenario(self::getContainer());
    }

    public function testEveryTypeIsStoredInItsShape(): void
    {
        $text = $this->itemField('Shelf');
        $number = $this->scenario->readyMade(self::OWNER, StandardCustomField::PricePaid);
        $date = $this->scenario->readyMade(self::OWNER, StandardCustomField::BoughtOn);
        $yesNo = $this->itemField('Framed', CustomFieldType::YesNo);
        $choice = $this->scenario->readyMade(self::OWNER, StandardCustomField::Condition);
        $tags = $this->scenario->readyMade(self::OWNER, StandardCustomField::MyTags);
        $rating = $this->scenario->readyMade(self::OWNER, StandardCustomField::Quality);

        $this->scenario->setItemValues(self::ITEM, self::OWNER, [
            $text => '  top shelf ',
            $number => '12,5',
            $date => '2026-03-01',
            $yesNo => 'yes',
            $choice => 'like_new',
            $tags => 'gradient, Cats , gradient',
            $rating => '4',
        ]);

        self::assertEquals([
            $text => 'top shelf',
            $number => 12.5,
            $date => '2026-03-01',
            $yesNo => true,
            $choice => 'like_new',
            $tags => ['gradient', 'Cats'],
            $rating => 4,
        ], $this->scenario->storedItemValues(self::ITEM));
        self::assertSame(4, $this->scenario->storedItemValues(self::ITEM)[$rating] ?? null, 'A rating is a whole number');
        // Nothing else of the item changed
        self::assertSame('Beautiful landscape puzzle, one of my favorites!', $this->database->fetchOne('SELECT comment FROM collection_item WHERE id = :id', ['id' => self::ITEM]));
    }

    public function testOnlyTheFieldsGivenChangeAndAnEmptyValueIsTakenAway(): void
    {
        $shelf = $this->itemField('Shelf');
        $copies = $this->itemField('Copies of it', CustomFieldType::Number);
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top', $copies => '2']);

        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$copies => '3']);
        self::assertEquals([$shelf => 'top', $copies => 3], $this->scenario->storedItemValues(self::ITEM));

        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => '  ']);
        self::assertSame([$copies => 3], $this->scenario->storedItemValues(self::ITEM));

        // Nothing left: the column is empty again, not an empty object
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$copies => null]);
        self::assertNull($this->scenario->storedItemValues(self::ITEM));
        self::assertNull($this->database->fetchOne('SELECT custom_values FROM collection_item WHERE id = :id', ['id' => self::ITEM]));
    }

    public function testAnotherPlayersItemIsNotFound(): void
    {
        // With a field of their own, for collection items - still not their item
        $ownField = $this->scenario->field(self::SOMEBODY_ELSE, 'Shelf', forResults: false, forCollectionItems: true);

        $refusal = $this->refusalOf(fn () => $this->messageBus->dispatch(new SetCollectionItemCustomValues(self::ITEM, self::SOMEBODY_ELSE, [$ownField => 'mine now'])));

        self::assertInstanceOf(CollectionItemNotFound::class, $refusal);
        self::assertNull($this->scenario->storedItemValues(self::ITEM));
        self::assertInstanceOf(CollectionItemNotFound::class, $this->refusalOf(fn () => $this->messageBus->dispatch(new SetCollectionItemCustomValues(Uuid::uuid7()->toString(), self::OWNER, []))));
    }

    public function testAnotherPlayersFieldAnArchivedOneAndOneForResultsOnlyAreNoSuchField(): void
    {
        $shelf = $this->itemField('Shelf');
        $ofSomebodyElse = $this->scenario->field(self::SOMEBODY_ELSE, 'Shelf', forResults: false, forCollectionItems: true);
        $archived = $this->itemField('Old field');
        $this->messageBus->dispatch(new ArchiveCustomField($archived, self::OWNER));
        $forResults = $this->scenario->field(self::OWNER, 'Notes on a solve');

        foreach ([$ofSomebodyElse, $archived, $forResults, Uuid::uuid7()->toString(), 'not-an-id'] as $fieldId) {
            // Together with a good value: one refused field refuses them all
            $refusal = $this->refusalOf(fn () => $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top', $fieldId => 'x']));

            self::assertInstanceOf(InvalidCustomValues::class, $refusal, $fieldId);
            self::assertSame([$fieldId => CustomValueProblem::UnknownField], $refusal->problems);
            self::assertNull($this->scenario->storedItemValues(self::ITEM), $fieldId);
        }
    }

    public function testAValueThatIsNotOfTheFieldsTypeRefusesTheSave(): void
    {
        $shelf = $this->itemField('Shelf');
        $price = $this->itemField('Price', CustomFieldType::Number);
        $bought = $this->itemField('Bought', CustomFieldType::Date);
        $framed = $this->itemField('Framed', CustomFieldType::YesNo);
        $place = $this->itemField('Place', CustomFieldType::Choice, ['attic', 'cellar']);
        $labels = $this->itemField('Labels', CustomFieldType::Tags);
        $stars = $this->itemField('Stars', CustomFieldType::Rating);
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top']);

        $refusal = $this->refusalOf(fn () => $this->scenario->setItemValues(self::ITEM, self::OWNER, [
            $shelf => str_repeat('a', 501),
            $price => 'a lot',
            $bought => 'last spring',
            $framed => 'maybe',
            $place => 'garage',
            $labels => str_repeat('a', 51),
            $stars => '6',
        ]));

        self::assertInstanceOf(InvalidCustomValues::class, $refusal);
        self::assertSame([
            $shelf => CustomValueProblem::TooLong,
            $price => CustomValueProblem::NotANumber,
            $bought => CustomValueProblem::NotADate,
            $framed => CustomValueProblem::NotYesNo,
            $place => CustomValueProblem::UnknownChoice,
            $labels => CustomValueProblem::TagTooLong,
            $stars => CustomValueProblem::NotARating,
        ], $refusal->problems);
        self::assertSame([$shelf => 'top'], $this->scenario->storedItemValues(self::ITEM), 'Nothing of a refused save is stored');
    }

    public function testWhatComesBackAsItIsStoredIsNoChange(): void
    {
        $place = $this->itemField('Place', CustomFieldType::Choice, ['attic', 'cellar']);
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$place => 'attic']);
        // The choice leaves the list; the item keeps it, and the form sends it back
        $this->messageBus->dispatch(new EditCustomField($place, self::OWNER, 'Place', ['cellar'], false, true));

        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$place => 'attic']);

        self::assertSame([$place => 'attic'], $this->scenario->storedItemValues(self::ITEM));
        self::assertInstanceOf(InvalidCustomValues::class, $this->refusalOf(fn () => $this->scenario->setItemValues(self::ITEM, self::OWNER, [$place => 'garage'])));
    }

    public function testACopyStartsWithTheValuesOfItsSource(): void
    {
        $shelf = $this->itemField('Shelf');
        $stars = $this->itemField('Stars', CustomFieldType::Rating);
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top', $stars => '5']);

        $this->messageBus->dispatch(new CopyPuzzlesToCollection(self::OWNER, [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_04], self::SOURCE, self::TARGET));
        $this->scenario->forgetLoadedEntities();

        $copy = $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_01, self::TARGET);
        self::assertNotNull($copy);
        self::assertNotSame(self::ITEM, $copy);
        self::assertEquals([$shelf => 'top', $stars => 5], $this->scenario->storedItemValues($copy));
        self::assertEquals([$shelf => 'top', $stars => 5], $this->scenario->storedItemValues(self::ITEM), 'The source keeps its own');
        // An item without values is copied without any
        $plainCopy = $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_04, self::TARGET);
        self::assertNotNull($plainCopy);
        self::assertNull($this->scenario->storedItemValues($plainCopy));

        // Two items from now on: a change of one is not a change of the other
        $this->scenario->setItemValues($copy, self::OWNER, [$shelf => 'bottom']);
        self::assertSame('top', $this->scenario->storedItemValues(self::ITEM)[$shelf] ?? null);
    }

    public function testAMovedItemKeepsItsValuesAndFillsTheGapsOfAnItemTheTargetAlreadyHolds(): void
    {
        $shelf = $this->itemField('Shelf');
        $price = $this->itemField('Price', CustomFieldType::Number);
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top', $price => '9']);
        // 500_02 is in both collections: each item has its own values
        $leaving = (string) $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::SOURCE);
        $staying = (string) $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::TARGET);
        $this->scenario->setItemValues($leaving, self::OWNER, [$shelf => 'of the leaving item', $price => '20']);
        $this->scenario->setItemValues($staying, self::OWNER, [$shelf => 'of the staying item']);
        // 1000_05 as well: the leaving item has values, the staying one none
        $leavingToEmpty = (string) $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_1000_05, self::SOURCE);
        $stayingEmpty = (string) $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_1000_05, self::TARGET);
        $this->scenario->setItemValues($leavingToEmpty, self::OWNER, [$price => '30']);

        $this->messageBus->dispatch(new MovePuzzlesToCollection(self::OWNER, [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1000_05], self::SOURCE, self::TARGET));
        $this->scenario->forgetLoadedEntities();

        // Moved: the same row, with everything on it
        self::assertSame(self::ITEM, $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_01, self::TARGET));
        self::assertEquals([$shelf => 'top', $price => 9], $this->scenario->storedItemValues(self::ITEM));
        // Two items became one: what the staying one holds stays, its gaps are filled from the leaving one
        self::assertNull($this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::SOURCE));
        self::assertEquals([$shelf => 'of the staying item', $price => 20], $this->scenario->storedItemValues($staying));
        self::assertEquals([$price => 30], $this->scenario->storedItemValues($stayingEmpty));
    }

    public function testTheSingleMoveDoesTheSame(): void
    {
        $shelf = $this->itemField('Shelf');
        $price = $this->itemField('Price', CustomFieldType::Number);
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top']);
        $leaving = (string) $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::SOURCE);
        $staying = (string) $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::TARGET);
        $this->scenario->setItemValues($leaving, self::OWNER, [$shelf => 'of the leaving item', $price => '20']);
        $this->scenario->setItemValues($staying, self::OWNER, [$shelf => 'of the staying item']);

        $this->messageBus->dispatch(new MovePuzzleToCollection(self::OWNER, PuzzleFixture::PUZZLE_500_01, self::SOURCE, self::TARGET, 'moved'));
        $this->messageBus->dispatch(new MovePuzzleToCollection(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::SOURCE, self::TARGET, null));
        $this->scenario->forgetLoadedEntities();

        self::assertSame(self::ITEM, $this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_01, self::TARGET));
        self::assertSame([$shelf => 'top'], $this->scenario->storedItemValues(self::ITEM));
        self::assertNull($this->scenario->itemId(self::OWNER, PuzzleFixture::PUZZLE_500_02, self::SOURCE));
        self::assertEquals([$shelf => 'of the staying item', $price => 20], $this->scenario->storedItemValues($staying));
    }

    public function testAnItemRemovedFromACollectionTakesItsValuesAlong(): void
    {
        $shelf = $this->itemField('Shelf');
        $this->scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top']);

        $this->messageBus->dispatch(new RemovePuzzlesFromCollection(self::OWNER, [PuzzleFixture::PUZZLE_500_01], self::SOURCE));

        // The values are a column of the row - nothing is left behind anywhere
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM collection_item WHERE id = :id', ['id' => self::ITEM]));
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM collection_item WHERE custom_values IS NOT NULL'));
    }

    public function testADeletedAccountsItemsGoWithTheirValues(): void
    {
        // ITEM_26: PLAYER_PRIVATE's 500_05 in their system collection
        $leaving = PlayerFixture::PLAYER_PRIVATE;
        $shelf = $this->scenario->field($leaving, 'Shelf', forResults: false, forCollectionItems: true);
        $this->scenario->setItemValues(CollectionItemFixture::ITEM_26, $leaving, [$shelf => 'top']);

        $this->messageBus->dispatch(new DeletePlayer($leaving));

        // DeletePlayerHandler deletes the player's collection items - the values are a column of those rows
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM collection_item WHERE player_id = :id', ['id' => $leaving]));
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM collection_item WHERE custom_values IS NOT NULL'));
        self::assertSame(0, $this->scenario->fieldsOf($leaving));
    }

    /**
     * 500_05 is merged into 500_04. PLAYER_WITH_STRIPE has both in COLLECTION_PUBLIC (ITEM_27 meets ITEM_21),
     * PLAYER_ADMIN both in the system collection (ITEM_25 meets ITEM_28), PLAYER_PRIVATE only the merged one once its
     * item of the survivor is gone (ITEM_26 moves).
     */
    public function testAPuzzleMergeKeepsTheValuesOfAMovedItemAndFillsTheGapsOfTheSurvivorsItem(): void
    {
        $shelf = $this->itemField('Shelf');
        $price = $this->itemField('Price', CustomFieldType::Number);
        $this->scenario->setItemValues(CollectionItemFixture::ITEM_21, self::OWNER, [$shelf => 'quokka-survivor-shelf']);
        $this->scenario->setItemValues(CollectionItemFixture::ITEM_27, self::OWNER, [$shelf => 'quokka-merged-shelf', $price => '41']);
        // The admin: only the merged item holds anything
        $adminNotes = $this->scenario->field(PlayerFixture::PLAYER_ADMIN, 'Notes', forResults: false, forCollectionItems: true);
        $this->scenario->setItemValues(CollectionItemFixture::ITEM_25, PlayerFixture::PLAYER_ADMIN, [$adminNotes => 'quokka-admin-notes']);
        // The private player: the merged puzzle only - the item moves onto the survivor
        $this->messageBus->dispatch(new RemovePuzzlesFromCollection(PlayerFixture::PLAYER_PRIVATE, [PuzzleFixture::PUZZLE_500_04], null));
        $privateNotes = $this->scenario->field(PlayerFixture::PLAYER_PRIVATE, 'Notes', forResults: false, forCollectionItems: true);
        $this->scenario->setItemValues(CollectionItemFixture::ITEM_26, PlayerFixture::PLAYER_PRIVATE, [$privateNotes => 'quokka-private-notes']);

        $mergeRequestId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_04,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_05],
        ));
        $this->messageBus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'Merged puzzle',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_TREFL,
            selectedImagePuzzleId: null,
        ));
        $this->scenario->forgetLoadedEntities();

        // Two items of one player in one collection became one: the survivor's item keeps what it holds, its gaps
        // are filled from the merged one
        self::assertEquals([$shelf => 'quokka-survivor-shelf', $price => 41], $this->scenario->storedItemValues(CollectionItemFixture::ITEM_21));
        self::assertSame([$adminNotes => 'quokka-admin-notes'], $this->scenario->storedItemValues(CollectionItemFixture::ITEM_28));
        self::assertEquals(0, $this->database->fetchOne('SELECT COUNT(*) FROM collection_item WHERE id IN (:a, :b)', ['a' => CollectionItemFixture::ITEM_27, 'b' => CollectionItemFixture::ITEM_25]));
        // An item that moves onto the survivor is the same row with everything on it
        self::assertSame(PuzzleFixture::PUZZLE_500_04, $this->database->fetchOne('SELECT puzzle_id FROM collection_item WHERE id = :id', ['id' => CollectionItemFixture::ITEM_26]));
        self::assertSame([$privateNotes => 'quokka-private-notes'], $this->scenario->storedItemValues(CollectionItemFixture::ITEM_26));

        // Private values: nothing of them in the merge audit, which outlives the items and the players
        $audit = $this->database->fetchAllAssociative('SELECT * FROM puzzle_merge_audit');
        self::assertCount(1, $audit);
        $auditText = json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertStringContainsString(CollectionItemFixture::ITEM_27, $auditText, 'The audit names the items it moved or dropped');
        self::assertStringNotContainsString('quokka', $auditText);
        self::assertStringNotContainsString($shelf, $auditText);
        self::assertStringNotContainsString('custom', strtolower($auditText));
    }

    /**
     * @param list<string> $choices
     */
    private function itemField(string $name, CustomFieldType $type = CustomFieldType::Text, array $choices = []): string
    {
        return $this->scenario->field(self::OWNER, $name, $type, $choices, forResults: false, forCollectionItems: true);
    }

    private function refusalOf(callable $action): null|\Throwable
    {
        try {
            $action();
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious();
        } catch (CollectionItemNotFound $notFound) {
            // A "not found" comes out of the bus as it is (UnwrapHttpExceptionMiddleware)
            return $notFound;
        }

        return null;
    }
}
