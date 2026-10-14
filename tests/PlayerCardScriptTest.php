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
     * CARD-07: a trigger opened in a new tab or window instead - Ctrl/Cmd/Shift/Alt with the primary button, or the
     * middle button (auxclick) - is one msp:card-bypass {context, input}; only where a plain click would have opened
     * the card
     */
    public function testATriggerOpenedInANewTabIsCountedAsABypass(): void
    {
        $bypass = self::results()['bypass'];
        self::assertIsArray($bypass);

        self::assertSame('msp:card-bypass', $bypass['eventName']);
        self::assertSame([['context' => 'puzzle', 'input' => 'mouse']], $bypass['ctrlInARow']);
        self::assertSame([['context' => 'none', 'input' => 'mouse']], $bypass['meta']);
        self::assertSame([['context' => 'none', 'input' => 'touch']], $bypass['shiftTouch']);
        self::assertSame([['context' => 'none', 'input' => 'keyboard']], $bypass['altKeyboard']);
        self::assertSame([['context' => 'none', 'input' => 'mouse']], $bypass['middle']);

        foreach (['middleAsClick', 'right', 'plain', 'handledElsewhere', 'native', 'notATrigger', 'insideModal', 'reverted'] as $case) {
            self::assertSame([], $bypass[$case], $case);
        }

        self::assertSame(['ctrl' => true, 'middle' => true, 'plain' => false, 'rightWithCtrl' => false, 'noPlayer' => false], self::results()['bypassOf']);
    }

    /**
     * CARD-07: every open is one card_open and ends in exactly one card_outcome - the first control used inside the card,
     * else bounce (closed within 2 s, nothing used) or close; another name while open ends the first card first
     */
    public function testEachOpenEndsInExactlyOneOutcome(): void
    {
        $measure = self::results()['measure'];
        self::assertIsArray($measure);

        self::assertSame(['msp:card-open', 'msp:card-outcome'], $measure['events']);
        self::assertSame(2000, $measure['bounceMs']);

        $open = static fn (string $context, string $input): array => ['event' => 'msp:card-open', 'context' => $context, 'input' => $input];
        $outcome = static fn (string $context, string $input, string $outcome, int $ms): array => ['event' => 'msp:card-outcome', 'context' => $context, 'input' => $input, 'outcome' => $outcome, 'ms' => $ms];

        self::assertSame([$open('puzzle', 'touch'), $outcome('puzzle', 'touch', 'bounce', 500)], $measure['bounce']);
        self::assertSame([$open('puzzle', 'touch'), $outcome('puzzle', 'touch', 'close', 2000)], $measure['closeAfterLooking']);
        // The × closes - it is no use of the card: a quick × is a bounce
        self::assertSame([$open('none', 'mouse'), $outcome('none', 'mouse', 'bounce', 300)], $measure['quickX']);
        // The ⋯ toggle (no data-nav) is a use, not an outcome: closed soon after it is a close, not a bounce
        self::assertSame([$open('feed', 'touch'), $outcome('feed', 'touch', 'close', 400)], $measure['touchedThenClosed']);
        self::assertSame([$open('leaderboard', 'keyboard'), $outcome('leaderboard', 'keyboard', 'continue', 1200)], $measure['profile']);
        self::assertSame([$open('round', 'pen'), $outcome('round', 'pen', 'action', 900)], $measure['firstWins']);
        self::assertSame([
            $open('feed', 'mouse'),
            $outcome('feed', 'mouse', 'bounce', 800),
            $open('notification', 'mouse'),
            $outcome('notification', 'mouse', 'continue', 3000),
        ], $measure['swap']);
        self::assertSame([], $measure['nothingOpen']);
        self::assertSame([$open('puzzle', 'mouse'), $outcome('puzzle', 'mouse', 'close', 5000)], $measure['closedTwice']);

        self::assertSame([
            'profile' => 'continue',
            'overview' => 'continue',
            'view_profile' => 'continue',
            'results' => 'deep',
            'statistics' => 'deep',
            'puzzle' => 'puzzle',
            'favorite' => 'action',
            'compare' => 'action',
            'compare_open' => 'action',
            'message' => 'action',
            'share' => 'action',
            'tier_locked' => 'action',
            'edit_result' => 'action',
            'own_results' => 'action',
            'edit_profile' => 'action',
            'close' => null,
            '' => null,
            'null' => null,
        ], self::results()['outcomeOf']);
    }

    /**
     * The card's data-nav items (templates/players/_card.html.twig, "card/<kind>/<item>") are the ones the outcome rule
     * knows - a new control on the card decides its outcome here (assets/player_card_measure.js)
     */
    public function testTheOutcomeRuleKnowsEveryControlOfTheCard(): void
    {
        $items = [];

        foreach (['players/_card.html.twig', 'players/_card_unavailable.html.twig'] as $template) {
            $content = (string) file_get_contents(__DIR__ . '/../templates/' . $template);
            // {{ nav }}item, nav: nav ~ 'item' (an included partial's data-nav), data-nav="card/none/item" (no context)
            preg_match_all('#\{\{ nav \}\}([a-z_]+)|nav: nav ~ \'([a-z_]+)\'|data-nav="card/none/([a-z_]+)"#', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $items[] = implode('', array_slice($match, 1));
            }
        }

        $items = array_values(array_unique(array_filter($items)));
        sort($items);

        self::assertSame(
            ['close', 'compare', 'compare_open', 'edit_profile', 'edit_result', 'favorite', 'message', 'overview', 'own_results', 'profile', 'puzzle', 'results', 'share', 'statistics', 'tier_locked', 'view_profile'],
            $items,
        );

        $known = self::results()['outcomeOf'];
        self::assertIsArray($known);

        foreach ($items as $item) {
            self::assertArrayHasKey($item, $known, $item . ' has no outcome decided');
        }
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
