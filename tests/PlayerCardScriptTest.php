<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The player card's trigger (docs/features/navigation README §4.8, CARD-01a) under node
 * (tests/player-card-harness.mjs): which clicks open the card and which keep following the link to the profile, whose
 * card it is, how it was asked for, its URL - and the listener itself, with a fake layer module.
 */
final class PlayerCardScriptTest extends TestCase
{
    private const string ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string OTHER = '0190ffff-0000-7000-8000-000000000001';

    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testOnlyAPlainPrimaryClickOpensTheCard(): void
    {
        self::assertSame([
            'plain' => true,
            // Enter on a focused name opens it too (the card's name link reaches the profile)
            'keyboard' => true,
            // A new tab or window: the profile, as always
            'ctrl' => false,
            'meta' => false,
            'shift' => false,
            'alt' => false,
            'middle' => false,
            'defaultPrevented' => false,
            // No triggers inside modals or foreign dialogs - the Menu and the card itself hand their entry over
            'insideModal' => false,
            'insideForeignDialog' => false,
            'insideMenu' => true,
            'insideCard' => true,
            // Inside the native app the link navigates (NAV-14)
            'native' => false,
            'targetBlank' => false,
            'targetSelf' => true,
            'download' => false,
            'noPlayer' => false,
            // The revert rule (CARD-07): a context and input sent straight to the profile, nothing else
            'reverted' => false,
            'revertedOtherInput' => true,
            'revertedNoContext' => false,
        ], self::results()['shouldOpen']);
    }

    public function testThePlayerIsTheTriggersOwnIdOrTheLastIdOfItsPath(): void
    {
        self::assertSame([
            'profile' => self::ID,
            'ownValue' => self::OTHER,
            'junkValue' => self::ID,
            // ?return= and ?team= name other ids - the path decides (Results and team links, PROF-05e)
            'returnParam' => self::ID,
            'teamParam' => self::ID,
            'absolute' => self::ID,
            'none' => null,
            'noHref' => null,
        ], self::results()['playerIdOf']);
    }

    public function testTheInputIsTheKeyboardWithoutAPointer(): void
    {
        self::assertSame([
            'keyboard' => 'keyboard',
            'mouse' => 'mouse',
            'touch' => 'touch',
            'pen' => 'pen',
            // Safari's click carries no pointerType: the last pointerdown's
            'safariTouch' => 'touch',
            'unknown' => 'mouse',
        ], self::results()['inputOf']);
    }

    public function testTheContextAndTheCardUrl(): void
    {
        self::assertSame([
            'row' => 'puzzle:' . self::OTHER,
            'none' => null,
            'kindPuzzle' => 'puzzle',
            'kindBoard' => 'leaderboard',
            'kindNone' => 'none',
            'kindJunk' => 'none',
        ], self::results()['context']);

        self::assertSame([
            'plain' => '/en/puzzler-card/' . self::ID,
            'context' => '/en/puzzler-card/' . self::ID . '?context=leaderboard%3Atimes-500%3Acz',
            'noTemplate' => null,
            'noPlayer' => null,
        ], self::results()['cardUrl']);

        self::assertSame(['string' => true, 'set' => true, 'missing' => false, 'nothing' => false], self::results()['reverted']);
    }

    public function testThePrefetchCacheAndTheGuestsReturnPage(): void
    {
        self::assertSame([
            'fresh' => true,
            'stale' => false,
            'future' => false,
            'empty' => false,
            // A guest's sign-in links come back to the page the card was opened on (the card response stays the same
            // for every guest - shared cache, decision 73)
            'returnLogin' => '/login?return=%2Fen%2Fpuzzle%2F' . self::OTHER . '%3Ftab%3Dpairs',
            'returnFavorite' => '/en/add-player-to-favorites/' . self::ID . '?return=%2Fen%2Fhub',
            'notAPath' => null,
        ], self::results()['layer']);
    }

    public function testTheListenerOpensTheCardInsteadOfFollowingTheLink(): void
    {
        $card = '/en/puzzler-card/' . self::ID;
        $listener = self::results()['listener'];
        self::assertIsArray($listener);

        self::assertSame(['prevented' => true, 'loads' => 1, 'opened' => [['url' => $card, 'input' => 'mouse']], 'assigned' => null], $listener['plain']);
        self::assertSame(['prevented' => true, 'loads' => 1, 'opened' => [['url' => $card, 'input' => 'touch']], 'assigned' => null], $listener['touch']);
        self::assertSame(['prevented' => true, 'loads' => 1, 'opened' => [['url' => $card . '?context=puzzle%3A' . self::OTHER, 'input' => 'mouse']], 'assigned' => null], $listener['withContext']);

        // Left to the browser (and Turbo): nothing prevented, the layer not even loaded
        foreach (['ctrl', 'native', 'notATrigger'] as $case) {
            self::assertSame(['prevented' => false, 'loads' => 0, 'opened' => [], 'assigned' => null], $listener[$case], $case);
        }

        // The layer could not load: the link goes to the profile after all
        self::assertSame(['prevented' => true, 'loads' => 1, 'opened' => [], 'assigned' => 'http://site/en/player-profile/' . self::ID], $listener['layerFailed']);
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
        self::assertIsString($node, 'node is required to execute the card modules - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/player-card-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
