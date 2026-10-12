<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Help sheet's openers (assets/help_openers.js run under node, tests/help-openers-harness.mjs; HELP phase review):
 * - F4: an opener attaches the page as it is at the tap (location.pathname + location.search), not as it was rendered -
 *   a Live Component or replaceState changed the address meanwhile;
 * - Q2: an opener on a page whose URL holds a private token keeps the token-free path the server gave it;
 * - F5: on the help page itself an opener leads to the page's own box (an open layer closes first) instead of opening
 *   the sheet - with its form and ids - over it.
 */
final class HelpOpenersScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testTheUrlIsThePageAsItIsNow(): void
    {
        self::assertSame([
            'page' => '/en/help?url=%2Fen%2Fpuzzle%3Fsearch%3Dabc%26pieces%3D500',
            'otherParams' => '/en/help?x=1&url=%2Fen%2Ffaq%3Ftab%3D2#top',
            // The address as the browser has it (percent-encoded path), like the server's own request URI
            'ja' => '/ja/%E3%83%98%E3%83%AB%E3%83%97?url=%2Fja%2F%25E3%2583%2591%25E3%2582%25BA%25E3%2583%25AB%3Fq%3D1',
            // The bare help page carries no page: nothing to follow
            'bare' => null,
            'notAPath' => null,
            'broken' => null,
        ], self::results()['rewrite']);
    }

    public function testAnOpenerFollowsThePageAtTheTap(): void
    {
        self::assertSame([
            'href' => '/en/help?url=%2Fen%2Fpuzzle%3Fsearch%3Dabc',
            'prevented' => false,
            // A private link keeps its token-free path (Q2) - never the address with its token
            'privateHref' => '/en/help?url=%2Fen%2Fnewsletter%2Fconfirm%2F%7Btoken%7D',
            'handledHref' => '/en/help?url=%2Fen%2Fpuzzle',
            'middleHref' => '/en/help?url=%2Fen%2Fpuzzle%3Fsearch%3Dabc',
            'plainHref' => '/en/faq',
            'layersUsed' => 0,
        ], self::results()['follow']);
        self::assertSame(0, self::results()['uninstalled']);
    }

    public function testOnTheHelpPageAnOpenerLeadsToThePagesOwnBox(): void
    {
        self::assertSame([
            'prevented' => true,
            'layers' => ['closeThen'],
            'focused' => true,
            'scrolled' => ['block' => 'center'],
            'href' => '/en/help',
            'newTabPrevented' => false,
        ], self::results()['ownBox']);
        self::assertSame(['prevented' => true, 'focused' => true], self::results()['guestPage']);
        self::assertSame(['field' => 'f', 'none' => null, 'noPage' => null], self::results()['targets']);
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
        self::assertIsString($node, 'node is required to execute the openers module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/help-openers-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
