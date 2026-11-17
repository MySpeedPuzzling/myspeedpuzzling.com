<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioPageBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The analytics studio's page (docs/features/competitions-management/analytics-studio.md §1.1, S3): a data-free shell
 * per round of a publicly visible event - the event route, the edition route (an edition's event path 301s there), 404
 * for a missing or slug-less round and for a draft (its team included), never indexed, and no slot, entrant, result or
 * puzzle in its HTML. The organisers' id-based link (analytics_studio_round) redirects to it.
 */
final class AnalyticsStudioPageTest extends WebTestCase
{
    private const string GROUP_A = '/en/events/results-cup/analytics-studio/group-a';
    private const string EDITION = '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/analytics-studio/main-round';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testTheEventRoundPageIsADataFreeShell(): void
    {
        $crawler = $this->browser->request('GET', self::GROUP_A);
        self::assertResponseIsSuccessful();

        $response = $this->browser->getResponse();
        self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        self::assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertCount(0, $crawler->filter('link[rel="canonical"], link[rel="alternate"]'));

        // The shell: where the answers are, the labels, the round switcher - every slugged round of the event
        $root = $crawler->filter('#analytics-studio');
        self::assertSame('/analytics-studio/' . F::ROUND_GROUP_A . '/state', $root->attr('data-state-url'));
        self::assertSame('/analytics-studio/' . F::ROUND_GROUP_A . '/analytics', $root->attr('data-analytics-url'));
        self::assertSame('/analytics-studio/' . F::ROUND_GROUP_A . '/predictions', $root->attr('data-predictions-url'));
        self::assertSame('/analytics-studio/' . F::ROUND_GROUP_A . '/entrant/' . $root->attr('data-entry-placeholder'), $root->attr('data-entrant-url'));
        $labels = json_decode((string) $root->attr('data-labels'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($labels);
        self::assertSame(AnalyticsStudioPageBuilder::LABEL_NAMES, array_keys($labels));
        self::assertSame('Solved before', $labels['sort.solves']);
        self::assertSame(
            ['group-a', 'group-b', 'pairs', 'final', 'pairs-final'],
            $crawler->filter('[data-analytics-studio-round]')->each(static fn ($link): string => (string) $link->attr('data-analytics-studio-round')),
        );
        self::assertSame('page', $crawler->filter('[data-analytics-studio-round="group-a"]')->attr('aria-current'));
        self::assertSame('/en/events/results-cup/analytics-studio/pairs', $crawler->filter('[data-analytics-studio-round="pairs"]')->attr('href'));
        self::assertSame('nofollow', $crawler->filter('[data-analytics-studio-round="pairs"]')->attr('rel'));
        self::assertSame('/en/events/results-cup', $crawler->filter('.as-brand__logo')->attr('href'));

        // Nothing of the round's data: no entrant, entry, result or puzzle
        $html = (string) $response->getContent();
        $puzzleName = $this->database->fetchOne('SELECT name FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_1000_05]);
        self::assertIsString($puzzleName);

        foreach (['Anna Fast', 'Ben Steady', F::ENTRY_A_ANNA, F::PARTICIPANT_ANNA, PuzzleFixture::PUZZLE_1000_05, $puzzleName, '1:00:00'] as $data) {
            self::assertStringNotContainsString($data, $html);
        }

        // No site chrome, no site bundle: the studio's own script (+ Encore's runtime) only
        self::assertStringNotContainsString('/build/app', $html);
        $scripts = $crawler->filter('script[src]')->each(static fn ($script): string => (string) $script->attr('src'));
        self::assertSame([], array_filter($scripts, static fn (string $src): bool => preg_match('~/build/(runtime|analytics_studio)[.\w]*\.js$~', $src) !== 1));
    }

    public function testEveryLocaleHasThePage(): void
    {
        foreach (['/eventy/results-cup/analytics-studio/group-a', '/de/veranstaltungen/results-cup/analytics-studio/group-a', '/ja/イベント/results-cup/analytics-studio/group-a'] as $url) {
            $this->browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }

    public function testAnEditionsStudioLivesUnderTheSeriesUrl(): void
    {
        $crawler = $this->browser->request('GET', self::EDITION);
        self::assertResponseIsSuccessful();
        self::assertSame('/analytics-studio/' . CompetitionSeriesFixture::ROUND_EJJ_68 . '/state', $crawler->filter('#analytics-studio')->attr('data-state-url'));
        self::assertSame(self::EDITION, $crawler->filter('[data-analytics-studio-round="main-round"]')->attr('href'));

        $this->browser->request('GET', '/en/events/ejj-68-february-2026/analytics-studio/main-round');
        self::assertResponseRedirects(self::EDITION, 301);
    }

    public function testNoPageForAMissingOrSluglessRound(): void
    {
        $this->browser->request('GET', '/en/events/results-cup/analytics-studio/no-such-round');
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/events/no-such-event/analytics-studio/group-a');
        self::assertResponseStatusCodeSame(404);

        $this->database->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => F::ROUND_GROUP_B]);
        $this->browser->request('GET', '/en/events/results-cup/analytics-studio/group-b');
        self::assertResponseStatusCodeSame(404);
        // ...nor in the switcher of the others
        $crawler = $this->browser->request('GET', self::GROUP_A);
        self::assertCount(0, $crawler->filter('[data-analytics-studio-round="group-b"]'));
    }

    public function testADraftOrUnapprovedEventHasNoStudioForAnybody(): void
    {
        $this->database->executeStatement('UPDATE competition SET is_draft = true WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        $this->browser->request('GET', self::GROUP_A);
        self::assertResponseStatusCodeSame(404);
        // Its team neither - like the round results pages, nothing of an event that is not public is shown
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', self::GROUP_A);
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/analytics-studio/' . F::ROUND_GROUP_A);
        self::assertResponseStatusCodeSame(404);

        $this->database->executeStatement('UPDATE competition SET is_draft = false, approved_at = NULL WHERE id = :id', ['id' => F::COMPETITION_RESULTS_CUP]);
        $this->browser->request('GET', self::GROUP_A);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOrganisersLinkByIdRedirectsToTheStudio(): void
    {
        $this->browser->request('GET', '/en/analytics-studio/' . F::ROUND_PAIRS);
        self::assertResponseRedirects('/en/events/results-cup/analytics-studio/pairs', 302);
        self::assertSame('noindex, nofollow', $this->browser->getResponse()->headers->get('X-Robots-Tag'));

        $this->browser->request('GET', '/de/analytics-studio/' . F::ROUND_PAIRS);
        self::assertResponseRedirects('/de/veranstaltungen/results-cup/analytics-studio/pairs', 302);

        $this->browser->request('GET', '/en/analytics-studio/' . CompetitionSeriesFixture::ROUND_EJJ_68);
        self::assertResponseRedirects(self::EDITION, 302);

        $this->database->executeStatement('UPDATE competition_round SET slug = NULL WHERE id = :id', ['id' => F::ROUND_PAIRS]);
        $this->browser->request('GET', '/en/analytics-studio/' . F::ROUND_PAIRS);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The page hands the browser modules every label render.js reads (LABEL_NAMES, the same list) - each one in the
     * English catalogue under `analytics_studio.`.
     */
    public function testEveryLabelOfTheRendererIsTranslated(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../assets/analytics-studio/render.js');
        self::assertSame(1, preg_match('/export const LABEL_NAMES = \[(.*?)\];/s', $source, $match));
        self::assertSame(1 <= preg_match_all("/'([^']+)'/", $match[1], $names) ? $names[1] : [], AnalyticsStudioPageBuilder::LABEL_NAMES);

        $translator = self::getContainer()->get(TranslatorInterface::class);
        $catalogue = $translator->getCatalogue('en');
        $missing = [];

        foreach ([...AnalyticsStudioPageBuilder::LABEL_NAMES, 'page.title', 'page.switch_round', 'page.no_script', 'page.gone', 'organiser.link', 'organiser.title', 'organiser.help'] as $name) {
            if (!$catalogue->defines('analytics_studio.' . $name)) {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing);
    }
}
