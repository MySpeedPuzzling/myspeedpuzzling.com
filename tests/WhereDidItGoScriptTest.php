<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The filter of "Where did it go?" (docs/features/navigation LAUNCH-02): the real
 * assets/controllers/where_did_it_go_controller.js under Stimulus in jsdom (tests/where-did-it-go-harness.mjs) on the
 * page rendered here.
 * - Its status line is a live region that stays the element the server rendered, never hidden (LAUNCH review F5): empty
 *   while nothing is typed, the number of matching rows (the plural message chosen like PHP), "Nothing matches" when
 *   nothing is left - written only when its text changes.
 * - A filter that leaves nothing is reported once the typing paused, once per query (LAUNCH review Q3): one
 *   msp:search-zero {surface: 'wdig', q} for the P0-03 beacon; a query under three characters never.
 */
final class WhereDidItGoScriptTest extends WebTestCase
{
    // nav_search.js ZERO_IDLE_MS (1500) and a little
    private const int PAUSE = 1700;

    public function testTheStatusLineAndTheZeroReport(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MEMBER);

        $facts = $this->runInNode([
            'member' => [
                'html' => $this->page($browser),
                'steps' => [
                    ['type' => 'export'],
                    // The same words: the status keeps its text and is not written again
                    ['type' => 'export '],
                    // The bell and the envelope are both "Top bar › Inbox" now
                    ['type' => 'inbox'],
                    ['type' => 'xy'],
                    ['wait' => self::PAUSE],
                    ['type' => 'xyzzyq'],
                    ['wait' => self::PAUSE],
                    // Typed again: reported once per page view
                    ['type' => 'xyzzyq'],
                    ['wait' => self::PAUSE],
                    ['type' => ''],
                ],
            ],
        ])['member'];

        self::assertSame([], $facts['errors']);
        self::assertTrue($facts['connected']);
        self::assertTrue($facts['barShown']);
        self::assertSame('', $facts['initialStatus']);

        [$export, $exportAgain, $inbox, $short, $shortPause, $zero, $zeroPause, $zeroAgain, $zeroAgainPause, $cleared] = $facts['steps'];

        foreach ([$export, $exportAgain, $inbox, $short, $zero, $zeroAgain, $cleared] as $step) {
            self::assertTrue($step['sameStatus'], $step['type'] . ': the status line is the server\'s, shown');
            self::assertSame('status', $step['role']);
        }

        self::assertGreaterThan(0, $export['rows']);
        self::assertSame($export['rows'] === 1 ? '1 item matches' : $export['rows'] . ' items match', $export['status']);
        self::assertGreaterThan(0, $export['writes']);
        self::assertSame($export['status'], $exportAgain['status']);
        self::assertSame(0, $exportAgain['writes'], 'written only when the text changes');
        self::assertSame(2, $inbox['rows']);
        self::assertSame('2 items match', $inbox['status']);

        // Nothing left: no row, no section, "Nothing matches"
        self::assertSame(0, $short['rows']);
        self::assertSame(0, $short['sections']);
        self::assertSame('Nothing matches. Try another word, or look in ☰ Menu.', $short['status']);
        self::assertSame(0, $zero['rows']);
        self::assertSame($short['status'], $zero['status']);
        self::assertSame(0, $zero['writes']);

        // Reported once the typing paused - not a two-letter query, the same query once
        self::assertSame([], $shortPause['events']);
        self::assertSame([['surface' => 'wdig', 'q' => 'xyzzyq']], $zeroPause['events']);
        self::assertSame([['surface' => 'wdig', 'q' => 'xyzzyq']], $zeroAgainPause['events']);

        // Cleared: every row back, the status empty again
        self::assertSame($cleared['allRows'], $cleared['rows']);
        self::assertSame('', $cleared['status']);
    }

    /**
     * The app's page names no ☰ when nothing matches (LAUNCH review F6)
     */
    public function testTheAppsNothingMatchesNamesNoMenu(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MEMBER);

        $facts = $this->runInNode([
            'app' => [
                'html' => $this->page($browser, NativeUserAgents::IOS),
                'steps' => [['type' => 'inbox'], ['type' => 'xyzzyq']],
            ],
        ])['app'];

        self::assertSame([], $facts['errors']);
        // The app's rows are found by their old names only: "Inbox" is a new name
        self::assertSame(0, $facts['steps'][0]['rows']);
        self::assertSame('Nothing matches. Try another word.', $facts['steps'][1]['status']);
    }

    private function page(KernelBrowser $browser, null|string $userAgent = null): string
    {
        $browser->request('GET', '/en/where-did-it-go', server: $userAgent !== null ? ['HTTP_USER_AGENT' => $userAgent] : []);
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    /**
     * @param array<string, array{html: string, steps: list<array{type: string}|array{wait: int}>}> $pages
     * @return array<string, array{errors: list<string>, connected: bool, barShown: bool, initialStatus: null|string, steps: list<array{type: string, wait: int, rows: int, allRows: int, sections: int, status: null|string, sameStatus: bool, role: null|string, writes: int, events: list<array{surface: string, q: string}>}>}>
     */
    private function runInNode(array $pages): array
    {
        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/where-did-it-go-harness.mjs']);
        $process->setInput(json_encode(['pages' => $pages], JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->mustRun();

        /** @var array<string, array{errors: list<string>, connected: bool, barShown: bool, initialStatus: null|string, steps: list<array{type: string, wait: int, rows: int, allRows: int, sections: int, status: null|string, sameStatus: bool, role: null|string, writes: int, events: list<array{surface: string, q: string}>}>}> $facts */
        $facts = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $facts;
    }
}
