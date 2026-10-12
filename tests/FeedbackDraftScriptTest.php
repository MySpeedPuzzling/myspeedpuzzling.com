<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Help sheet's feedback draft (docs/features/navigation README §4.5, HELP-01b): assets/feedback_draft.js run under
 * node (tests/feedback-draft-harness.mjs). Typed text is kept in sessionStorage `msp:feedback-draft` as {player, text},
 * comes back into an empty field only and only for the same player, an empty field keeps nothing, the sent state clears
 * it, and blocked storage never throws.
 */
final class FeedbackDraftScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testTypedTextIsKeptUnderOneKey(): void
    {
        $results = self::results();

        self::assertSame('msp:feedback-draft', $results['key']);
        self::assertSame([
            'stored' => ['player' => '018d0000-0000-0000-0000-0000000000a1', 'text' => 'The chart is upside down'],
            'read' => 'The chart is upside down',
        ], $results['saved']);
    }

    /**
     * Review F8: logging out keeps the tab and its sessionStorage - on a shared device the next player who signs in and
     * opens Help never gets the previous player's unsent text
     */
    public function testTheDraftComesBackOnlyForTheSamePlayer(): void
    {
        self::assertSame([
            'restoredForB' => false,
            'valueForB' => '',
            'restoredForA' => true,
            'valueForA' => 'Unsent words of A',
            'readByNobody' => '',
            'guestKept' => false,
            'legacy' => '',
            'broken' => '',
            'afterB' => ['a' => '', 'b' => 'Words of B'],
        ], self::results()['perPlayer']);
    }

    public function testAnEmptyFieldKeepsNothing(): void
    {
        self::assertSame(['afterBlank' => false, 'afterEmpty' => false, 'read' => ''], self::results()['blank']);
    }

    public function testTheDraftComesBackIntoAnEmptyFieldOnly(): void
    {
        self::assertSame([
            'intoEmpty' => true,
            'emptyValue' => 'My draft',
            'intoFilled' => false,
            'filledValue' => 'Sent back by the server',
            'noField' => false,
            'nothingKept' => false,
        ], self::results()['restore']);
    }

    public function testTheSentStateClearsIt(): void
    {
        self::assertSame(['stored' => false, 'restored' => false, 'value' => ''], self::results()['cleared']);
    }

    public function testBlockedStorageNeverThrows(): void
    {
        self::assertSame([
            'threw' => false,
            'read' => '',
            'restored' => false,
            'nullRead' => '',
            'windowWithout' => null,
            'windowBlocked' => null,
            'noWindow' => null,
            'windowWith' => true,
        ], self::results()['blocked']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the draft module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/feedback-draft-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
