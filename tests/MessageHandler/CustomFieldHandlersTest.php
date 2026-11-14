<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\CustomFieldLimitReached;
use SpeedPuzzling\Web\Exceptions\CustomFieldNameTaken;
use SpeedPuzzling\Web\Exceptions\CustomFieldNotFound;
use SpeedPuzzling\Web\Exceptions\InvalidCustomFieldDefinition;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Message\EditCustomField;
use SpeedPuzzling\Web\Message\ReorderCustomFields;
use SpeedPuzzling\Web\Query\GetCustomFields;
use SpeedPuzzling\Web\Results\CustomFieldOverview;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A player's own fields: add, ready-made, edit, order, archive (docs/features/custom-fields.md).
 */
final class CustomFieldHandlersTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES;

    private MessageBusInterface $messageBus;
    private CustomFieldsScenario $scenario;
    private GetCustomFields $getCustomFields;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->scenario = new CustomFieldsScenario(self::getContainer());
        $this->getCustomFields = self::getContainer()->get(GetCustomFields::class);
    }

    public function testAFieldIsAddedCleanedAndAtTheEndOfThePlayersOrder(): void
    {
        $this->scenario->field(self::PLAYER, 'Mood');
        $this->scenario->field(self::PLAYER, '  Where   I puzzled ', CustomFieldType::Choice, [' table ', 'floor', 'Table', '', 'floor']);
        // Another player's fields are another player's
        $this->scenario->field(self::OTHER_PLAYER, 'Mood');

        $fields = $this->getCustomFields->ofPlayer(self::PLAYER);

        self::assertSame(['Mood', 'Where I puzzled'], $this->names($fields));
        self::assertSame(CustomFieldType::Choice, $fields[1]->type);
        self::assertSame(['table', 'floor'], $fields[1]->choices);
        self::assertTrue($fields[1]->forResults);
        self::assertFalse($fields[1]->forCollectionItems);
        self::assertNull($fields[1]->standard);
        self::assertSame([], $fields[0]->choices, 'Only "one of a list" has choices');
        self::assertSame(['Mood'], $this->names($this->getCustomFields->ofPlayer(self::OTHER_PLAYER)));
    }

    public function testANameIsThereOncePerPlayerWhateverItsLetterCase(): void
    {
        $this->scenario->field(self::PLAYER, 'Mood');

        self::assertInstanceOf(CustomFieldNameTaken::class, $this->refusalOf(fn () => $this->scenario->field(self::PLAYER, ' mood ')));
        // The name of a ready-made field the player has is taken as well, and the other way round
        $this->scenario->readyMade(self::PLAYER, StandardCustomField::PrivateNotes);
        self::assertInstanceOf(CustomFieldNameTaken::class, $this->refusalOf(fn () => $this->scenario->field(self::PLAYER, 'Private Notes')));
        $this->scenario->field(self::PLAYER, 'My tags');
        self::assertInstanceOf(CustomFieldNameTaken::class, $this->refusalOf(fn () => $this->scenario->readyMade(self::PLAYER, StandardCustomField::MyTags)));

        self::assertSame(3, $this->scenario->fieldsOf(self::PLAYER));
    }

    public function testAtMostTwentyFieldsInUse(): void
    {
        for ($i = 1; $i <= CustomField::MAX_ACTIVE_PER_PLAYER; $i++) {
            $lastId = $this->scenario->field(self::PLAYER, 'Field ' . $i);
        }

        $refusal = $this->refusalOf(fn () => $this->scenario->field(self::PLAYER, 'One too many'));
        self::assertInstanceOf(CustomFieldLimitReached::class, $refusal);
        self::assertFalse($refusal->withArchived, 'The twenty in use - archiving one helps');
        self::assertInstanceOf(CustomFieldLimitReached::class, $this->refusalOf(fn () => $this->scenario->readyMade(self::PLAYER, StandardCustomField::MyTags)));
        self::assertSame(CustomField::MAX_ACTIVE_PER_PLAYER, $this->scenario->fieldsOf(self::PLAYER));

        // An archived field frees its place - and can not come back while the twenty are taken
        $this->messageBus->dispatch(new ArchiveCustomField($lastId, self::PLAYER));
        $this->scenario->field(self::PLAYER, 'Now it fits');
        self::assertInstanceOf(CustomFieldLimitReached::class, $this->refusalOf(fn () => $this->messageBus->dispatch(new ArchiveCustomField($lastId, self::PLAYER, archived: false))));
    }

    public function testNamesAndChoicesTheDatabaseCouldNotHoldAreRefused(): void
    {
        $refused = [
            'a name that is no UTF-8' => fn () => $this->scenario->field(self::PLAYER, "Place \xC3\x28"),
            'a name with an escape character' => fn () => $this->scenario->field(self::PLAYER, "Place\x1B"),
            'a choice with a NUL byte' => fn () => $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', "flo\0or"]),
            'a choice that is no UTF-8' => fn () => $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ["\xFF"]),
        ];

        foreach ($refused as $what => $action) {
            $refusal = $this->refusalOf($action);

            self::assertInstanceOf(InvalidCustomFieldDefinition::class, $refusal, $what);
            self::assertSame(InvalidCustomFieldDefinition::INVALID_CHARACTERS, $refusal->reason, $what);
        }

        // An edit is checked the same way
        $fieldId = $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table']);
        $refusal = $this->refusalOf(fn () => $this->messageBus->dispatch(new EditCustomField($fieldId, self::PLAYER, "Pla\0ce", ['table'], true, false)));
        self::assertInstanceOf(InvalidCustomFieldDefinition::class, $refusal);
        self::assertSame(InvalidCustomFieldDefinition::INVALID_CHARACTERS, $refusal->reason);

        // A line break or a tab in a name is white space like any other
        $this->scenario->field(self::PLAYER, "Two\nlines\tname");
        self::assertSame(['Place', 'Two lines name'], $this->names($this->getCustomFields->ofPlayer(self::PLAYER)));
    }

    public function testTheHardCapCountsTheArchivedFieldsAndSaysSo(): void
    {
        // Sixty fields, every one archived: none in use, no room for a new one
        for ($i = 1; $i <= CustomField::MAX_PER_PLAYER; $i++) {
            $lastId = $this->scenario->field(self::PLAYER, 'Field ' . $i);
            $this->messageBus->dispatch(new ArchiveCustomField($lastId, self::PLAYER));
        }

        $refusal = $this->refusalOf(fn () => $this->scenario->field(self::PLAYER, 'One too many'));
        self::assertInstanceOf(CustomFieldLimitReached::class, $refusal);
        self::assertTrue($refusal->withArchived, 'Not the twenty in use - the sixty in all');

        $refusal = $this->refusalOf(fn () => $this->scenario->readyMade(self::PLAYER, StandardCustomField::MyTags));
        self::assertInstanceOf(CustomFieldLimitReached::class, $refusal);
        self::assertTrue($refusal->withArchived);

        // What the message tells the player to do works: an archived field comes back
        $this->messageBus->dispatch(new ArchiveCustomField($lastId, self::PLAYER, archived: false));
        self::assertCount(1, $this->getCustomFields->usableOnResults(self::PLAYER));
        self::assertSame(CustomField::MAX_PER_PLAYER, $this->scenario->fieldsOf(self::PLAYER));
    }

    public function testAFieldThatCanNotBeSetUpIsRefusedWithItsReason(): void
    {
        $refused = [
            InvalidCustomFieldDefinition::NAME_MISSING => fn () => $this->scenario->field(self::PLAYER, '   '),
            InvalidCustomFieldDefinition::NAME_TOO_LONG => fn () => $this->scenario->field(self::PLAYER, str_repeat('a', 61)),
            InvalidCustomFieldDefinition::CHOICES_MISSING => fn () => $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, [' ']),
            InvalidCustomFieldDefinition::TOO_MANY_CHOICES => fn () => $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, array_map(strval(...), range(1, 31))),
            InvalidCustomFieldDefinition::CHOICE_TOO_LONG => fn () => $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, [str_repeat('a', 51)]),
            InvalidCustomFieldDefinition::NO_TARGET => fn () => $this->scenario->field(self::PLAYER, 'Place', forResults: false),
            // What the database could not hold is refused as a wrong name - never an exception at the write
            InvalidCustomFieldDefinition::INVALID_CHARACTERS => fn () => $this->scenario->field(self::PLAYER, "Pla\0ce"),
        ];

        foreach ($refused as $reason => $action) {
            $refusal = $this->refusalOf($action);

            self::assertInstanceOf(InvalidCustomFieldDefinition::class, $refusal, $reason);
            self::assertSame($reason, $refusal->reason);
        }

        self::assertSame(0, $this->scenario->fieldsOf(self::PLAYER));
    }

    public function testAReadyMadeFieldIsTheRegistrysAndThereOnce(): void
    {
        $fieldId = $this->scenario->readyMade(self::PLAYER, StandardCustomField::MyTags);
        // Tapped again: nothing new
        self::assertSame($fieldId, $this->scenario->readyMade(self::PLAYER, StandardCustomField::MyTags));
        $conditionId = $this->scenario->readyMade(self::PLAYER, StandardCustomField::Condition);

        $fields = $this->getCustomFields->ofPlayer(self::PLAYER);

        self::assertCount(2, $fields);
        self::assertSame(StandardCustomField::MyTags, $fields[0]->standard);
        self::assertSame(CustomFieldType::Tags, $fields[0]->type);
        self::assertTrue($fields[0]->forResults);
        self::assertTrue($fields[0]->forCollectionItems);
        self::assertSame($conditionId, $fields[1]->fieldId);
        self::assertSame(['new', 'like_new', 'normal', 'not_so_good', 'missing_pieces'], $fields[1]->choices, 'The sell/swap list\'s conditions');
        self::assertFalse($fields[1]->forResults);
        self::assertFalse($fields[1]->isUsableOnResults());

        // Tapped while archived: it comes back, with the same id - its values were kept under it
        $this->messageBus->dispatch(new ArchiveCustomField($fieldId, self::PLAYER));
        self::assertSame($fieldId, $this->scenario->readyMade(self::PLAYER, StandardCustomField::MyTags));
        self::assertFalse($this->field($fieldId)->archived);

        // Its name and choices are not the player's to change
        $refusal = $this->refusalOf(fn () => $this->messageBus->dispatch(new EditCustomField($fieldId, self::PLAYER, 'Labels', [], true, true)));
        self::assertInstanceOf(InvalidCustomFieldDefinition::class, $refusal);
        self::assertSame(InvalidCustomFieldDefinition::READY_MADE, $refusal->reason);
    }

    public function testEveryReadyMadeFieldHasItsTextsAndHeaderWordsInAllSixLanguages(): void
    {
        $translator = self::getContainer()->get('translator');

        foreach (StandardCustomField::cases() as $standard) {
            self::assertTrue($standard->forResults() || $standard->forCollectionItems(), $standard->value);
            self::assertSame(['en', 'cs', 'de', 'es', 'fr', 'ja'], array_keys($standard->headerWords()), $standard->value);

            foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
                self::assertNotSame($standard->labelKey(), $translator->trans($standard->labelKey(), locale: $locale), $standard->value . ' ' . $locale);
                self::assertNotSame([], $standard->headerWords()[$locale], $standard->value . ' ' . $locale);

                foreach ($standard->choices() as $choice) {
                    $key = $standard->choiceLabelKey($choice);
                    self::assertNotSame($key, $translator->trans($key, locale: $locale), $key . ' ' . $locale);
                }
            }
        }
    }

    public function testAnOwnFieldIsRenamedItsChoicesAndPlacesChangeItsTypeNever(): void
    {
        $fieldId = $this->scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $this->scenario->field(self::PLAYER, 'Mood');

        $this->messageBus->dispatch(new EditCustomField($fieldId, self::PLAYER, 'Where', ['table', 'desk'], true, true));

        $field = $this->field($fieldId);
        self::assertSame('Where', $field->name);
        self::assertSame(['table', 'desk'], $field->choices);
        self::assertSame(CustomFieldType::Choice, $field->type);
        self::assertTrue($field->forCollectionItems);

        // Its own name in another letter case is no clash; another field's name is
        $this->messageBus->dispatch(new EditCustomField($fieldId, self::PLAYER, 'WHERE', ['table'], true, false));
        self::assertInstanceOf(CustomFieldNameTaken::class, $this->refusalOf(fn () => $this->messageBus->dispatch(new EditCustomField($fieldId, self::PLAYER, 'mood', ['table'], true, false))));
        self::assertSame('WHERE', $this->field($fieldId)->name);
    }

    public function testAnotherPlayersFieldDoesNotExist(): void
    {
        $fieldId = $this->scenario->field(self::PLAYER, 'Mood');

        $this->expectNotFound(fn () => $this->messageBus->dispatch(new EditCustomField($fieldId, self::OTHER_PLAYER, 'Theirs now', [], true, false)));
        $this->expectNotFound(fn () => $this->messageBus->dispatch(new ArchiveCustomField($fieldId, self::OTHER_PLAYER)));
        $this->expectNotFound(fn () => $this->messageBus->dispatch(new ArchiveCustomField(Uuid::uuid7()->toString(), self::PLAYER)));

        $field = $this->field($fieldId);
        self::assertSame('Mood', $field->name);
        self::assertFalse($field->archived);
    }

    public function testTheOrderIsThePlayers(): void
    {
        $a = $this->scenario->field(self::PLAYER, 'A');
        $b = $this->scenario->field(self::PLAYER, 'B');
        $c = $this->scenario->field(self::PLAYER, 'C');
        $theirs = $this->scenario->field(self::OTHER_PLAYER, 'Theirs');

        // The ids given first; another player's id means nothing; a field left out keeps its place behind them
        $this->messageBus->dispatch(new ReorderCustomFields(self::PLAYER, [$c, $theirs, strtoupper($a)]));

        self::assertSame(['C', 'A', 'B'], $this->names($this->getCustomFields->ofPlayer(self::PLAYER)));
        self::assertSame(['Theirs'], $this->names($this->getCustomFields->ofPlayer(self::OTHER_PLAYER)));
        self::assertSame($b, $this->getCustomFields->ofPlayer(self::PLAYER)[2]->fieldId);
    }

    public function testAnArchivedFieldIsHiddenFromResultsAndItsValuesAreKept(): void
    {
        $fieldId = $this->scenario->field(self::PLAYER, 'Mood');
        $timeId = $this->scenario->result(PlayerFixture::PLAYER_REGULAR_USER_ID);
        $this->scenario->setValues($timeId, self::PLAYER, [$fieldId => 'calm']);

        $this->messageBus->dispatch(new ArchiveCustomField($fieldId, self::PLAYER));

        self::assertTrue($this->field($fieldId)->archived);
        self::assertSame([], $this->getCustomFields->usableOnResults(self::PLAYER));
        self::assertSame([$fieldId => 'calm'], $this->scenario->storedValues($timeId, self::PLAYER));

        $this->messageBus->dispatch(new ArchiveCustomField($fieldId, self::PLAYER, archived: false));

        self::assertFalse($this->field($fieldId)->archived);
        self::assertCount(1, $this->getCustomFields->usableOnResults(self::PLAYER));
        self::assertSame([$fieldId => 'calm'], $this->scenario->storedValues($timeId, self::PLAYER));
    }

    public function testTheTablesHoldWhatTheDesignSays(): void
    {
        $fieldId = $this->scenario->readyMade(self::PLAYER, StandardCustomField::WhatToWorkOn);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT player_id, name, standard_key, type, for_results, for_collection_items, position, archived_at FROM player_custom_field WHERE id = :id',
            ['id' => $fieldId],
        );

        self::assertSame([
            'player_id' => self::PLAYER,
            'name' => 'What to work on',
            'standard_key' => 'what_to_work_on',
            'type' => 'text',
            'for_results' => true,
            'for_collection_items' => false,
            'position' => 0,
            'archived_at' => null,
        ], $row);
    }

    private function field(string $fieldId): CustomFieldOverview
    {
        foreach ([self::PLAYER, self::OTHER_PLAYER] as $playerId) {
            foreach ($this->getCustomFields->ofPlayer($playerId) as $field) {
                if ($field->fieldId === $fieldId) {
                    return $field;
                }
            }
        }

        self::fail('No such field.');
    }

    /**
     * @param list<CustomFieldOverview> $fields
     * @return list<string>
     */
    private function names(array $fields): array
    {
        return array_map(static fn (CustomFieldOverview $field): string => $field->name, $fields);
    }

    private function refusalOf(callable $action): null|\Throwable
    {
        try {
            $action();
        } catch (HandlerFailedException $exception) {
            return $exception->getPrevious();
        }

        return null;
    }

    private function expectNotFound(callable $action): void
    {
        try {
            $action();
            self::fail('Nothing was refused.');
        } catch (CustomFieldNotFound) {
            // The handler's 404 comes out as itself (UnwrapHttpExceptionMiddleware)
            self::addToAssertionCount(1);
        }
    }
}
