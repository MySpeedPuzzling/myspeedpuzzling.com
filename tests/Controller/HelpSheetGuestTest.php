<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Help & feedback for guests (docs/features/navigation README §4.5, implementation plan HELP-03, decision 56): the help
 * links, the language row and "Sign in to send feedback" - back to the help page with the same page attached. No form,
 * no CSRF token, no session: the guest page stays shared-cacheable. `feedback` itself stays signed-in only.
 */
final class HelpSheetGuestTest extends WebTestCase
{
    private const string PUZZLE_PAGE = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

    public function testTheGuestPage(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode(self::PUZZLE_PAGE));

        self::assertResponseIsSuccessful();
        self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame([], $browser->getResponse()->headers->getCookies());

        $sheet = $crawler->filter('main [data-help-sheet]');
        self::assertSame(
            ['feedback_sign_in', 'suggest_correction', 'feature_requests', 'faq', 'getting_started', 'progression', 'language', 'reload'],
            $sheet->filter('[data-help-item]')->each(static fn (Crawler $item): string => (string) $item->attr('data-help-item')),
        );
        self::assertCount(0, $crawler->filter('form[name="feedback_form"]'));
        self::assertCount(0, $sheet->filter('form, [name="_token"], [name$="[_token]"], textarea'));

        $this->assertSignInLeadsBackToTheHelpPage($sheet);
    }

    public function testTheGuestSheet(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode(self::PUZZLE_PAGE), server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        // The frame shares its URL with the page: never shared-cached
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringNotContainsString('public', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $sheet = $crawler->filter('turbo-frame#modal-frame > [data-help-sheet]');
        self::assertCount(0, $sheet->filter('form, [name="_token"], textarea'));
        // Nothing to fall back to: guests send nothing (HELP-05 (d))
        self::assertNull($sheet->attr('data-modal-fallback-url'));

        $this->assertSignInLeadsBackToTheHelpPage($sheet);
    }

    public function testAGuestCannotSendFeedback(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/feedback', ['feedback_form' => ['message' => 'Hello', 'url' => '/en/faq']]);

        self::assertResponseRedirects();
        self::assertStringStartsWith('/login', (string) parse_url((string) $browser->getResponse()->headers->get('Location'), PHP_URL_PATH));
        self::assertCount(0, self::getMailerMessages());
    }

    /**
     * "Sign in to send feedback" returns to the help page - the full page - with the same page attached (decision 56); the
     * correction leads through sign-in to the puzzle's "Suggest a change"
     */
    private function assertSignInLeadsBackToTheHelpPage(Crawler $sheet): void
    {
        $signIn = $sheet->filter('[data-help-item="feedback_sign_in"] a');
        self::assertCount(1, $signIn);
        self::assertSame('Sign in to send feedback', trim($signIn->text()));
        self::assertSame('_top', $signIn->attr('data-turbo-frame'));
        self::assertSame('help/help/sign_in', $signIn->attr('data-nav'));
        self::assertSame('/login', parse_url((string) $signIn->attr('href'), PHP_URL_PATH));
        parse_str((string) parse_url((string) $signIn->attr('href'), PHP_URL_QUERY), $query);
        self::assertSame('/en/help?url=' . self::PUZZLE_PAGE, $query['return'] ?? null);

        $correction = $sheet->filter('[data-help-item="suggest_correction"] a');
        self::assertSame('_top', $correction->attr('data-turbo-frame'));
        self::assertSame('/login', parse_url((string) $correction->attr('href'), PHP_URL_PATH));
        parse_str((string) parse_url((string) $correction->attr('href'), PHP_URL_QUERY), $query);
        self::assertSame(self::PUZZLE_PAGE . '/suggest-change', $query['return'] ?? null);
    }
}
