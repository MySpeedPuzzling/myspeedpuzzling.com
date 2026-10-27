<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The buyer's selection across sell/swap lists and the marketplace (assets/listing_selection_store.js,
 * docs/features/marketplace/12-listing-requests.md): kept in sessionStorage per player, so it survives paging, filters
 * and moving between pages. Runs the real module under node with a fake storage.
 */
final class ListingSelectionStoreScriptTest extends TestCase
{
    public function testTheSelectionSurvivesALaterPageOfTheSamePlayer(): void
    {
        $results = $this->runSteps([
            ['op' => 'open', 'key' => 'msp:listing-selection:jana'],
            ['op' => 'add', 'id' => 'l1', 'seller' => 's1'],
            ['op' => 'add', 'id' => 'l2', 'seller' => 's2'],
            ['op' => 'add', 'id' => 'l3', 'seller' => 's1'],
            // The next page (marketplace page 2, the seller's list) opens the same key
            ['op' => 'open', 'store' => 'b', 'key' => 'msp:listing-selection:jana'],
            ['op' => 'open', 'store' => 'other', 'key' => 'msp:listing-selection:karel'],
        ]);

        self::assertSame(['ids' => ['l1', 'l2', 'l3'], 'sellers' => 2], $results[4]);
        self::assertSame(['ids' => [], 'sellers' => 0], $results[5]);
    }

    public function testRemovingClearingAndAddingTwice(): void
    {
        $results = $this->runSteps([
            ['op' => 'open'],
            ['op' => 'add', 'id' => 'l1', 'seller' => 's1'],
            ['op' => 'add', 'id' => 'l1', 'seller' => 's1'],
            ['op' => 'add', 'id' => 'l2', 'seller' => 's1'],
            ['op' => 'remove', 'id' => 'l1'],
            ['op' => 'clear'],
            ['op' => 'open', 'store' => 'b'],
        ]);

        self::assertSame(['ids' => ['l2'], 'sellers' => 1], $results[4]);
        self::assertSame(['ids' => [], 'sellers' => 0], $results[5]);
        self::assertSame(['ids' => [], 'sellers' => 0], $results[6]);
    }

    public function testAPickOlderThanADayIsDropped(): void
    {
        $results = $this->runSteps([
            ['op' => 'open'],
            ['op' => 'add', 'id' => 'old', 'seller' => 's1'],
            ['op' => 'advance', 'ms' => 23 * 3600 * 1000],
            ['op' => 'add', 'id' => 'new', 'seller' => 's2'],
            ['op' => 'advance', 'ms' => 2 * 3600 * 1000],
            ['op' => 'open', 'store' => 'b'],
        ]);

        self::assertSame(['ids' => ['new'], 'sellers' => 1], $results[3]);
    }

    public function testAtMostThirtyPicks(): void
    {
        $steps = [['op' => 'open']];

        for ($i = 1; $i <= 31; $i++) {
            $steps[] = ['op' => 'add', 'id' => 'l' . $i, 'seller' => 's'];
        }

        $results = $this->runSteps($steps);

        self::assertTrue($results[30]);
        self::assertFalse($results[31]);
    }

    public function testStorageThatThrowsStillSelectsOnThisPage(): void
    {
        $results = $this->runSteps([
            ['op' => 'open', 'throwing' => true],
            ['op' => 'add', 'id' => 'l1', 'seller' => 's1'],
            ['op' => 'remove', 'id' => 'l9'],
        ]);

        self::assertTrue($results[1]);
        self::assertSame(['ids' => ['l1'], 'sellers' => 1], $results[2]);
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @return list<mixed>
     */
    private function runSteps(array $steps): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/listing-selection-store-harness.mjs']);
        $process->setInput(json_encode($steps, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<mixed> $results */
        $results = json_decode($process->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
