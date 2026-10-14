<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The × of a dismissable hint (docs/features/hint-dismissing.md): the real assets/controllers/dismiss_hint_controller.js
 * under Stimulus in jsdom (tests/dismiss-hint-harness.mjs) on the Hub rendered here inside the navigation launch's 30
 * days - its pointer is there twice, the banner and the line at the top of the Menu (LAUNCH-03, decision 52), and the
 * harness adds a hint of another type.
 * - One click POSTs the type once and removes every copy of that hint, never another type's (LAUNCH review F8).
 * - A focused × hands the focus on before its hint goes (LAUNCH review F5, WCAG 2.4.3): in the Menu to its first row,
 *   elsewhere to the page's heading - never to <body>. A click without the focus inside moves nothing.
 */
final class DismissHintScriptTest extends WebTestCase
{
    private const string BANNER = 'main .nav-pointer--hub';
    private const string LINE = '#site-menu > .nav-pointer--menu';

    public function testOneClickRemovesEveryCopyAndHandsTheFocusOn(): void
    {
        $facts = $this->runInNode([
            'the banner, focused' => ['click' => self::BANNER, 'focus' => true],
            'the Menu line, focused' => ['click' => self::LINE, 'focus' => true],
            'the banner, not focused' => ['click' => self::BANNER, 'focus' => false],
            'the other type' => ['click' => '.harness-other-hint', 'focus' => true],
        ]);

        $pointer = ['type' => 'navigation_launch_pointer', 'inMenu' => false];
        $line = ['type' => 'navigation_launch_pointer', 'inMenu' => true];
        $other = ['type' => 'getting_started_checklist', 'inMenu' => false];
        $post = static fn (string $type): array => ['url' => '/en/dismiss-hint', 'method' => 'POST', 'body' => 'type=' . $type];

        foreach ($facts as $case => $fact) {
            self::assertSame([], $fact['errors'], $case);
            // The Hub carries the pointer twice (the Menu line first in the DOM), plus the harness's other hint
            self::assertEqualsCanonicalizing([$line, $pointer, $other], $fact['before'], $case);
        }

        // One click: both copies gone, the other type kept, one request
        foreach (['the banner, focused', 'the Menu line, focused', 'the banner, not focused'] as $case) {
            self::assertSame([$other], $facts[$case]['after'], $case);
            self::assertSame([$post('navigation_launch_pointer')], $facts[$case]['requests'], $case);
        }

        self::assertEqualsCanonicalizing([$line, $pointer], $facts['the other type']['after']);
        self::assertSame([$post('getting_started_checklist')], $facts['the other type']['requests']);

        // The focus: the Hub's heading (made focusable), the Menu's first row, left alone when it was not inside
        self::assertSame('button', $facts['the banner, focused']['focusedBefore']['tag'] ?? null);
        self::assertSame(['tag' => 'h1', 'inMenu' => false, 'inMain' => true, 'href' => null, 'tabindex' => '-1'], $facts['the banner, focused']['active']);
        $active = $facts['the Menu line, focused']['active'];
        self::assertNotNull($active);
        self::assertSame('a', $active['tag']);
        self::assertTrue($active['inMenu']);
        self::assertSame($facts['the Menu line, focused']['expectedFirstRow'], $active['href']);
        self::assertSame('body', $facts['the banner, not focused']['active']['tag'] ?? null);
        self::assertSame('h1', $facts['the other type']['active']['tag'] ?? null);
    }

    /**
     * @param array<string, array{click: string, focus: bool}> $cases
     * @return array<string, array{errors: list<string>, before: list<array{type: null|string, inMenu: bool}>, after: list<array{type: null|string, inMenu: bool}>, requests: list<array{url: string, method: string, body: null|string}>, focusedBefore?: null|array{tag: string, inMenu: bool, inMain: bool, href: null|string, tabindex: null|string}, active: null|array{tag: string, inMenu: bool, inMain: bool, href: null|string, tabindex: null|string}, expectedFirstRow: null|string}>
     */
    private function runInNode(array $cases): array
    {
        $browser = self::createClient();
        $browser->disableReboot();
        // Inside the launch's 30 days, an account older than the launch: the pointer is on the Hub and in the Menu
        self::getContainer()->set(NavigationLaunch::class, new NavigationLaunch(new MockClock('2026-11-10 12:00:00', 'Europe/Prague'), '2026-11-02'));
        self::getContainer()->get(Connection::class)->executeStatement("UPDATE player SET registered_at = '2025-05-01 10:00:00' WHERE id = :id", ['id' => PlayerFixture::PLAYER_REGULAR]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter(self::BANNER));
        self::assertCount(1, $crawler->filter(self::LINE));

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/dismiss-hint-harness.mjs']);
        $process->setInput(json_encode(['html' => (string) $browser->getResponse()->getContent(), 'cases' => $cases], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, array{errors: list<string>, before: list<array{type: null|string, inMenu: bool}>, after: list<array{type: null|string, inMenu: bool}>, requests: list<array{url: string, method: string, body: null|string}>, focusedBefore?: null|array{tag: string, inMenu: bool, inMain: bool, href: null|string, tabindex: null|string}, active: null|array{tag: string, inMenu: bool, inMain: bool, href: null|string, tabindex: null|string}, expectedFirstRow: null|string}> $facts */
        $facts = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $facts;
    }
}
