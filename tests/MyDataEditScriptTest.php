<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * docs/features/my-data.md "Editing in place" - the real controller of the wide table's cells
 * (assets/controllers/my_data_edit_controller.js, real Stimulus) in jsdom (tests/my-data-edit-harness.mjs): what a
 * double-click, Enter and Escape do, what is sent (ONE field, with what the cell showed when its editor opened), and
 * what the table shows after each answer of the cell endpoint. The endpoint itself is MyDataEditRowTest's.
 */
final class MyDataEditScriptTest extends TestCase
{
    private const string URL = '/en/my-data/cell/result/row-1';

    public function testADoubleClickOpensTheEditorAndEnterSavesThatOneField(): void
    {
        ['save' => $save, 'unchanged' => $unchanged, 'keyboard' => $keyboard] = $this->scenarios(['save', 'unchanged', 'keyboard']);

        // The editor holds what the cell held
        self::assertSame([true, 'Old comment'], [self::at($save, 'opened', 'editing'), self::at($save, 'opened', 'typed')]);
        // One request: the column, what the cell showed, the new value - and the token in its header
        self::assertSame([['url' => self::URL, 'method' => 'POST', 'token' => 'csrf-token', 'body' => ['column' => 'comment', 'from' => 'Old comment', 'value' => 'New comment']]], self::at($save, 'calls'));
        self::assertSame(['New comment', 'New comment', false], [self::at($save, 'after', 'text'), self::at($save, 'after', 'value'), self::at($save, 'after', 'editing')]);
        self::assertTrue(self::at($save, 'focused'), 'The keyboard stays on the cell');

        // Enter without a change sends nothing
        self::assertSame([], self::at($unchanged, 'calls'));
        self::assertSame(['Old comment', false], [self::at($unchanged, 'after', 'text'), self::at($unchanged, 'after', 'editing')]);

        // Enter or F2 on a focused cell opens it - typing a letter does not
        self::assertFalse(self::at($keyboard, 'afterLetter'));
        self::assertTrue(self::at($keyboard, 'afterEnter'));
        self::assertSame(['Old comment', false], [self::at($keyboard, 'afterEscape', 'text'), self::at($keyboard, 'afterEscape', 'editing')]);
        self::assertSame(['editing' => true, 'type' => 'date', 'typed' => '2026-03-01'], self::at($keyboard, 'date'));
    }

    public function testEscapePutsBackExactlyWhatTheCellShowed(): void
    {
        ['cancel' => $facts] = $this->scenarios(['cancel']);

        self::assertSame('1:02:03', self::at($facts, 'opened', 'typed'));
        self::assertSame([], self::at($facts, 'calls'));
        // Its own elements too: the label of the time, the <time> of the date
        self::assertSame(['01:02:03 Needs verification', '1:02:03', ['span.badge']], [self::at($facts, 'time', 'text'), self::at($facts, 'time', 'value'), self::at($facts, 'time', 'elements')]);
        self::assertSame(['01.03.2026', '2026-03-01', ['time']], [self::at($facts, 'date', 'text'), self::at($facts, 'date', 'value'), self::at($facts, 'date', 'elements')]);
    }

    public function testTheAnswerWritesEveryCellOfTheRowItNames(): void
    {
        ['rowCells' => $row, 'check' => $check, 'ownFields' => $own] = $this->scenarios(['rowCells', 'check', 'ownFields']);

        self::assertSame(['column' => 'time', 'from' => '1:02:03', 'value' => '1:00:00'], self::at($row, 'calls', 0, 'body'));
        self::assertSame(['01:00:00 Needs verification', '1:00:00', ['span.badge']], [self::at($row, 'time', 'text'), self::at($row, 'time', 'value'), self::at($row, 'time', 'elements')]);
        // A cell that is not edited in place follows too: the pieces per minute
        self::assertSame('8.33', self::at($row, 'ppm', 'text'));
        self::assertSame(['1', ['i.bi', 'span.visually-hidden']], [self::at($row, 'firstTry', 'value'), self::at($row, 'firstTry', 'elements')]);

        // A tick is a checkbox
        self::assertSame(['type' => 'checkbox', 'typed' => '0'], self::at($check, 'opened'));
        self::assertSame(['column' => 'first_try', 'from' => '0', 'value' => '1'], self::at($check, 'calls', 0, 'body'));
        self::assertSame(['1', ['i.bi', 'span.visually-hidden']], [self::at($check, 'after', 'value'), self::at($check, 'after', 'elements')]);

        // A value of an own field travels under the name nothing is logged with - never as `value`
        self::assertSame(['column' => 'f:notes', 'my_fields[from]' => '', 'my_fields[value]' => 'top shelf'], self::at($own, 'calls', 0, 'body'));
        self::assertSame(['column' => 'f:stars', 'my_fields[from]' => '9', 'my_fields[value]' => '2'], self::at($own, 'calls', 1, 'body'));
        self::assertSame('top shelf', self::at($own, 'notes', 'text'));
        // The select: the header's options, and the value the row holds although it is no option any more
        self::assertSame([['', 'No rating', false], ['1', '1 star', false], ['2', '2 stars', false], ['3', '3 stars', false], ['9', '9', true]], self::at($own, 'options'));
        self::assertSame(['2 stars', '2'], [self::at($own, 'stars', 'text'), self::at($own, 'stars', 'value')]);
    }

    public function testAValueChangedMeanwhileIsShownAndTheNextEditStartsFromIt(): void
    {
        ['conflict' => $facts] = $this->scenarios(['conflict']);

        self::assertSame(['Theirs', 'Theirs', false, 'CHANGED MEANWHILE'], [self::at($facts, 'after', 'text'), self::at($facts, 'after', 'value'), self::at($facts, 'after', 'editing'), self::at($facts, 'after', 'note')]);
        // The remark goes with the next thing done to the cell, the editor opens on what is stored now
        self::assertSame(['Theirs', null], [self::at($facts, 'reopened', 'typed'), self::at($facts, 'reopened', 'note')]);
        self::assertSame(['column' => 'comment', 'from' => 'Theirs', 'value' => 'Mine after all'], self::at($facts, 'calls', 1, 'body'));
        self::assertSame(['Mine after all', []], [self::at($facts, 'saved', 'text'), self::at($facts, 'saved', 'elements')]);
    }

    public function testARefusalKeepsTheEditorOpenAndAQuestionLeadsToTheRowSheet(): void
    {
        ['refused' => $refused, 'question' => $question, 'network' => $network] = $this->scenarios(['refused', 'question', 'network']);

        // What was typed stays, with the reason under it - no way to the row for a plain mistake
        self::assertSame([true, '4:75:00', false, 'ENTER THE TIME AS', null], [self::at($refused, 'after', 'editing'), self::at($refused, 'after', 'typed'), self::at($refused, 'after', 'disabled'), self::at($refused, 'after', 'note'), self::at($refused, 'after', 'link')]);
        // Fixed: sent again with what the cell showed when the editor opened
        self::assertSame(['column' => 'time', 'from' => '1:02:03', 'value' => '4:15:00'], self::at($refused, 'calls', 1, 'body'));
        self::assertSame(['04:15:00', false, null], [self::at($refused, 'saved', 'text'), self::at($refused, 'saved', 'editing'), self::at($refused, 'saved', 'note')]);

        // A question is never answered here: the row sheet is offered, in the modal
        self::assertSame('THIS PUZZLE ALREADY HAS A FIRST TRY', self::at($question, 'after', 'note'));
        self::assertSame(['text' => 'OPEN THE ROW', 'href' => '/en/my-data/result/row-1?return=/en/my-data', 'frame' => 'modal-frame'], self::at($question, 'after', 'link'));
        self::assertTrue(self::at($question, 'after', 'editing'));
        self::assertCount(1, (array) self::at($question, 'calls'), 'Nothing is sent a second time by itself');

        // No connection, or an answer that is no answer: said at the cell, what was typed is still there
        foreach (['offline', 'error'] as $case) {
            self::assertSame([true, 'Typed on a train', false, 'NOT SAVED'], [self::at($network, $case, 'editing'), self::at($network, $case, 'typed'), self::at($network, $case, 'disabled'), self::at($network, $case, 'note')], $case);
        }
    }

    public function testOneEditorAtATimeAndTypedTextIsNeverThrownAway(): void
    {
        ['oneAtATime' => $facts, 'notEditable' => $not] = $this->scenarios(['oneAtATime', 'notEditable']);

        // An untouched editor closes when another cell is opened
        self::assertSame(['comment' => false, 'date' => true], self::at($facts, 'unchanged'));
        // A changed one stays, says how to finish, and the other cell is not opened
        self::assertFalse(self::at($facts, 'comment'));
        self::assertSame([true, '2020-01-01', 'ENTER SAVES'], [self::at($facts, 'date', 'editing'), self::at($facts, 'date', 'typed'), self::at($facts, 'date', 'note')]);

        // A cell without `data-edit` and a row without an address to save to are left alone
        self::assertSame(['brand' => false, 'comment' => false], $not);
    }

    public function testWhatTheServerAnswersIsWrittenAsTextNeverAsMarkup(): void
    {
        ['textOnly' => $facts] = $this->scenarios(['textOnly']);
        $markup = '<img src=x onerror="window.hacked = true"><b>bold</b>';

        self::assertSame([$markup, []], [self::at($facts, 'comment', 'text'), self::at($facts, 'comment', 'elements')]);
        // The time's label is the one element made for it - its text is text
        self::assertSame([$markup . ' ' . $markup, ['span.badge']], [self::at($facts, 'time', 'text'), self::at($facts, 'time', 'elements')]);
        self::assertSame($markup, self::at($facts, 'note', 'note'));
        self::assertSame(0, self::at($facts, 'images'));
        self::assertFalse(self::at($facts, 'hacked'));
    }

    /**
     * No `innerHTML`, `outerHTML` or `insertAdjacentHTML` anywhere in the controller's code.
     */
    public function testTheControllerNeverWritesMarkup(): void
    {
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents(__DIR__ . '/../assets/controllers/my_data_edit_controller.js'));

        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|document\.write/', $code);
    }

    /**
     * @param array<mixed> $facts
     */
    private static function at(array $facts, string|int ...$path): mixed
    {
        $value = $facts;

        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value, implode('.', $path));
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param list<string> $names
     * @return array<string, array<mixed>>
     */
    private function scenarios(array $names): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/my-data-edit-harness.mjs']);
        $process->setInput((string) json_encode($names, JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->mustRun();

        /** @var list<array<mixed>> $answers */
        $answers = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertCount(count($names), $answers);

        foreach ($answers as $index => $answer) {
            $crashed = $answer['crashed'] ?? null;
            self::assertNull($crashed, $names[$index] . ': ' . (is_string($crashed) ? $crashed : ''));
        }

        return array_combine($names, $answers);
    }
}
