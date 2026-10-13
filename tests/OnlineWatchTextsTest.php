<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

/**
 * The texts of the watch page's JavaScript (templates/online_rounds/watch_texts.html.twig, assets/controllers/
 * online_watch_controller.js): a key the page asks for but the partial misses would show nothing where a sentence
 * belongs. The keys built from a value - the curtain's (assets/online/watch_view.js curtainFor(), its every answer
 * pinned by tests/online-watch-harness.mjs) and the clock's (clockLine()) - are listed here, so a new one fails until
 * it has its text.
 */
final class OnlineWatchTextsTest extends KernelTestCase
{
    private const array CURTAINS = ['connecting', 'reconnecting', 'not_live', 'quiet', 'off_air', 'full', 'unavailable', 'gone', 'lost'];
    private const array CLOCK_STATES = ['waiting', 'starts_in', 'running', 'ended'];

    public function testEveryTextTheWatchPageAsksForIsThere(): void
    {
        $texts = $this->render();
        $source = (string) file_get_contents(__DIR__ . '/../assets/controllers/online_watch_controller.js');
        preg_match_all('/this\.texts\.([a-z_]+)/', $source, $literal);
        $used = $literal[1];

        foreach (self::CURTAINS as $curtain) {
            $used[] = $curtain;
            $used[] = $curtain . '_text';
        }

        foreach (self::CLOCK_STATES as $state) {
            $used[] = 'clock_' . $state;
        }

        $missing = array_values(array_diff(array_unique($used), array_keys($texts)));

        self::assertSame([], $missing, 'texts the watch page uses but watch_texts.html.twig does not have');
        self::assertGreaterThan(20, count(array_unique($used)));

        foreach ($texts as $key => $text) {
            if ($key === 'watching') {
                self::assertIsArray($text);
                self::assertSame('en', $text['locale'] ?? null);
                self::assertIsString($text['message'] ?? null);
                self::assertStringContainsString('|', $text['message']);

                continue;
            }

            self::assertIsString($text, $key);
            self::assertStringNotContainsString('online_rounds.', $text, $key . ' - an untranslated key');

            // Only the connecting curtain has no second line
            if ($key !== 'connecting_text') {
                self::assertNotSame('', trim($text), $key);
            }
        }

        self::assertSame('Trying again in %seconds% s', $texts['retry_in']);
        self::assertSame('%time% left', $texts['clock_left']);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(): array
    {
        $container = self::bootKernel()->getContainer();
        $request = Request::create('/en/online-rounds/x/watch');
        $request->setLocale('en');
        $container->get('request_stack')->push($request);
        $container->get('router')->getContext()->setParameter('_locale', 'en');

        $texts = json_decode(self::getContainer()->get(Environment::class)->render('online_rounds/watch_texts.html.twig'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);

        /** @var array<string, mixed> $texts */
        return $texts;
    }
}
