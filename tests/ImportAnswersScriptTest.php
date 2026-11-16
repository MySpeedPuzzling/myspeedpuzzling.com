<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * docs/features/data-import/pairing-screens.md "Answers in place" - the real controller of the import's answer forms
 * (assets/controllers/data_import_answers_controller.js, real Stimulus) in jsdom (tests/import-answers-harness.mjs):
 * what a tap sends, what the card looks like while the answer is on its way, where focus goes after it, and that an
 * answer which could not be saved leaves the screen as it was. What the server answers is the controllers' tests
 * (ImportPuzzlesInBulkTest, ImportPeopleScreenTest).
 */
final class ImportAnswersScriptTest extends TestCase
{
    private const array WAITS = ['busy' => 'true', 'disabled' => [true, true], 'spinners' => 1, 'spinnerText' => 'SAVING', 'failed' => []];
    private const array AS_IT_WAS = ['busy' => null, 'disabled' => [false, false], 'spinners' => 0, 'spinnerText' => null, 'failed' => []];

    public function testATapIsSentOnceTheCardWaitsAndFocusGoesToTheCardThatMovedUp(): void
    {
        ['answered' => $answered, 'stays' => $stays, 'queued' => $queued, 'plain' => $plain] = $this->scenarios(['answered', 'stays', 'queued', 'plain']);

        // Three taps on the card while the answer is on its way: ONE request, with the value of the button tapped first
        self::assertSame(
            [['url' => 'https://example.test/en/import/1/puzzles/decide', 'method' => 'POST', 'accept' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml', 'body' => ['_token' => 'csrf', 'puzzle' => 'one', 'action' => 'catalogue']]],
            $answered['calls'],
        );
        // The card shows it is working - both its buttons are off, the tapped one turns; the other card is untouched
        self::assertSame(self::WAITS, $answered['waiting']);
        self::assertSame(self::AS_IT_WAS, $answered['other']);
        // The streams are put in place, nothing is visited, the address stays - and focus is on the next card's first button
        self::assertTrue($answered['gone']);
        self::assertSame(self::AS_IT_WAS, $answered['after']);
        self::assertSame('two button Yes, this is it', $answered['focused']);
        self::assertSame('1 matched.Undo the last one', $answered['said']);
        self::assertSame([0, '/en/import/1/puzzles/probably?part=differs'], [$answered['visits'], $answered['location']]);

        // A card that stays where it is: rendered again, focus on its first button
        self::assertSame(['_token' => 'csrf', 'puzzle' => 'two', 'action' => 'left_out'], $stays['body']);
        self::assertSame('two button Put back', $stays['focused']);

        // Two cards, one after the other: in that order, the second only when the first is answered - and the second
        // card stays switched off on the screen that was rendered again meanwhile
        self::assertSame([['one'], ['one', 'two']], [$queued['first'], $queued['second']]);
        self::assertSame([true, true], self::at($queued, 'between', 'disabled'));
        self::assertSame(0, $queued['left']);
        // No card is left: focus is on the line that says what happened
        self::assertSame('import-answer-said div 1 matched.', $queued['focused']);

        // A form that is not marked for the script is not touched
        self::assertSame([0, 'plain'], [$plain['calls'], $plain['leftToTheBrowser']]);
    }

    public function testAnAnswerThatCouldNotBeSavedLeavesTheCardAsItWasWithOneLine(): void
    {
        ['offline' => $offline, 'refused' => $refused, 'undone' => $undone] = $this->scenarios(['offline', 'refused', 'undone']);

        foreach (['no connection' => $offline, 'an error page' => $refused] as $what => $facts) {
            self::assertSame(array_replace(self::AS_IT_WAS, ['failed' => ['null: NOT SAVED']]), self::at($facts, 'failed'), $what);
            self::assertSame(2, $facts['cards'], $what);
        }

        // ... and it is said in the line that stays in view too - instead of "1 matched.", as an alert - and the
        // card's button, which stood under that line, is brought above it (694 - (700 - 90 - 8))
        self::assertSame(['said' => 'NOT SAVED', 'role' => 'alert', 'scrolledBy' => [92]], self::at($offline, 'inView'));

        // "Undo the last one" brings the card back: it is brought above the line and its first button is focused
        // (not "Close") - and "Close" keeps focus there, never on the card that was focused before the Undo
        self::assertSame('two button Yes, this is it', $undone['before']);
        self::assertSame(['puzzle' => 'one', 'action' => 'undecided'], $undone['sent']);
        self::assertSame(['focused' => 'one button Yes, this is it', 'scrolledBy' => [92]], self::at($undone, 'after'));
        self::assertSame('one button Yes, this is it', $undone['closed']);

        // Focus is back on the tapped button, and the next tap is sent - the line is gone while it is on its way
        self::assertSame('one button Yes, this is it', $offline['focused']);
        self::assertSame(self::WAITS, $offline['again']);
        self::assertSame(2, $offline['calls']);
        // An error page is never shown instead of the screen
        self::assertSame(0, $refused['visits']);
    }

    public function testAnotherScreenInItsPlaceIsShownFromItsBeginningAndARedirectIsNotAskedForTwice(): void
    {
        ['fresh' => $fresh, 'page' => $page] = $this->scenarios(['fresh', 'page']);

        // While an answer for the whole screen is on its way no card's tap is sent
        self::assertSame(1, $fresh['calls']);
        // The next puzzle took the place: the address is its address, its beginning is in view and focused
        self::assertSame('/en/import/1/puzzle/2?flow=choose&done=1', $fresh['location']);
        // (it stood at 300 px, below the header: nothing is scrolled)
        self::assertSame(['import-pile h1 Next puzzle', '-1', false, []], [$fresh['focused'], $fresh['tabindex'], $fresh['shown'], $fresh['scrolledBy']]);

        // A redirect (the screen is another one): the page that was fetched is handed to Turbo - one request
        self::assertSame(['disabled' => true, 'busy' => 'true'], $page['waiting']);
        self::assertSame(1, $page['calls']);
        self::assertSame(
            [['url' => 'https://example.test/en/import/1/puzzles', 'options' => ['action' => 'replace', 'response' => ['statusCode' => 200, 'responseHTML' => '<html><body>The piles</body></html>', 'redirected' => true]]]],
            $page['visits'],
        );
    }

    public function testTheAddressIsTheFormsAttributeAndTheEndOfAScreenAndAFailureAreInView(): void
    {
        ['answered' => $answered, 'ended' => $ended, 'inView' => $inView, 'code' => $code] = $this->scenarios(['answered', 'ended', 'inView', 'code']);

        // Every answer form holds `<button name="action">`: in a browser `form.action` is then that button (the
        // harness does what a browser does) - the address is read from the attribute
        self::assertSame('https://example.test/en/import/1/puzzles/decide', self::at($answered, 'calls', 0, 'url'));
        self::assertSame('https://example.test/en/import/1/people', $code['url']);
        $script = (string) file_get_contents(__DIR__ . '/../assets/controllers/data_import_answers_controller.js');
        self::assertDoesNotMatchRegularExpression('/\bform\.(action|method|id|name|target)\b/', $script, 'a control of that name shadows the property - use getAttribute()');

        // The last card answered: the way on is brought into view and focused - not "Undo the last one"
        // (the screen's heading stood at 73 px, behind the header: brought below it - 73 - (90 + 20))
        self::assertSame(['continue a Continue', [-37]], [$ended['focused'], $ended['scrolledBy']]);

        // An answer for the whole screen that failed: said in the line that stays in view, not at the form's end
        self::assertSame(['inSaid' => 1, 'inForm' => 0, 'text' => 'NOT SAVED', 'button' => false], $inView['failed']);
        // "Close" takes the line away and sends nothing
        self::assertSame([0, 1], [$inView['closed'], $inView['calls']]);

        // A typed code picks its answer
        self::assertSame(['answer' => 'code', 'code' => '#abc123'], $code['body']);
    }

    public function testTheLineAtTheBottomNeverCoversWhatIsAnsweredNext(): void
    {
        ['room' => $room] = $this->scenarios(['room']);

        // The next card's button stood at 650-694 of 700 px, the line is 90 px: the page keeps room for the line
        // and scrolls by what brings the button above it (694 - (700 - 90 - 8)), no more
        // (the line is fixed to the window: the page itself is that much longer, and a sticky bar stands on the line)
        self::assertSame(['scrolledBy' => [92], 'padding' => '98px', 'room' => '90px', 'said' => '90px', 'focused' => 'two button Yes, this is it'], self::at($room, 'under'));
        // "Close": the room is given back and focus returns to that button - not to the page
        self::assertSame(['focused' => 'two button Yes, this is it', 'padding' => '', 'room' => ''], self::at($room, 'closed'));
        // A button that is in view already: nothing moves
        self::assertSame([], self::at($room, 'inView'));
        // An answer that was not taken, its notice under the site's header (at 40 px, header 90 + 20): brought
        // below the header and focused
        self::assertSame(['scrolledBy' => [-70], 'focused' => 'two div No player has this code.'], self::at($room, 'refused'));
    }

    public function testTheWholeCardIsRevealedBetweenTheHeaderAndTheLineAtTheBottom(): void
    {
        ['fits' => $fits] = $this->scenarios(['fits']);
        $focused = 'two button Yes, this is it';

        // Scrolled far above (its top at -125): the top lands just below the header (110) - the puzzle is seen
        self::assertSame(['scrolledBy' => [-235], 'focused' => $focused], self::at($fits, 'above'));
        // Far below (900-1100, the room ends at 602): up until its bottom is above the line
        self::assertSame(['scrolledBy' => [498], 'focused' => $focused], self::at($fits, 'below'));
        // Taller than the room (600 px in 492): its top below the header - never its top cut off for its buttons
        self::assertSame(['scrolledBy' => [290], 'focused' => $focused], self::at($fits, 'taller'));
        // It fits where it stands: nothing moves
        self::assertSame(['scrolledBy' => [], 'focused' => $focused], self::at($fits, 'fits'));
    }

    public function testARowThatIsPutBackStaysInItsPlaceAndThePageAsksAgainUntilItIsReady(): void
    {
        ['comingBack' => $back] = $this->scenarios(['comingBack']);

        // The frame keeps room for its bar, which is fixed to the bottom of the window (120 px + 16)
        self::assertSame('136px', $back['room']);
        // Right after "Put back" the row is there, "coming back", and focus is on it - not on the page
        self::assertSame(['focused' => 'row-a div Put back. It is in the list again…', 'there' => true], self::at($back, 'coming'));
        // The frame is asked for again, and again while the row is still coming back ...
        self::assertSame([1, 2], [$back['asked'], $back['askedTwice']]);
        // ... and not any more once the row is ready: focus is on its tick box
        self::assertSame(2, $back['reloads']);
        self::assertSame("row-a button Don't import", $back['ready']);
    }

    public function testWhatThePlayerSetAndDidNotSendStaysWhenTheScreenIsRenderedAgain(): void
    {
        ['unsaved' => $unsaved] = $this->scenarios(['unsaved']);

        // Only the ticked box and the picked answer were sent
        self::assertSame(['cards[b][tick]' => '1', 'choice' => 'left_out', 'action' => 'tick_all'], $unsaved['sent']);
        // The server's screen came with every box ticked, the default answer and everything folded: the unticked
        // box is still unticked - and counted again -, "Don't import" still picked, the opened part still open
        self::assertSame(['ticked' => [false, true], 'choice' => 'left_out', 'open' => [true, false]], $unsaved['kept']);
        self::assertGreaterThanOrEqual(1, $unsaved['counted']);
        // The refusal is in the line that stays in view, and focus is on it
        self::assertSame('import-answer-said div Needs a closer look. Nothing was changed.', $unsaved['focused']);
        // Another screen in its place (the next twenty) starts as the server rendered it
        self::assertSame(['ticked' => [true, true], 'choice' => 'unlisted', 'open' => [false, false]], $unsaved['fresh']);
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

        $process = new Process([$node, __DIR__ . '/import-answers-harness.mjs']);
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
