<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class CompetitionBadgeTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    /**
     * @param array<string, null|string> $time
     */
    private function render(array $time): string
    {
        $defaults = [
            'competitionName' => null,
            'competitionShortcut' => null,
            'competitionSlug' => null,
            'competitionSeriesName' => null,
            'competitionSeriesShortcut' => null,
            'competitionSeriesSlug' => null,
        ];

        $html = $this->twig->render('_competition_badge.html.twig', [
            'time' => array_merge($defaults, $time),
        ]);

        // Collapse the template's indentation so assertions can target the markup itself.
        return trim((string) preg_replace('/\s+/', ' ', $html));
    }

    public function testStandaloneWithShortcutShowsShortcutAndLinksToEvent(): void
    {
        $html = $this->render([
            'competitionName' => 'WJPC 2024',
            'competitionShortcut' => 'WJPC24',
            'competitionSlug' => 'wjpc-2024',
        ]);

        self::assertStringStartsWith('<br>', $html);
        self::assertStringContainsString('<a href="/en/events/wjpc-2024">', $html);
        self::assertStringContainsString('<span class="badge rounded-pill bg-primary"><i class="bi bi-trophy-fill"></i> WJPC24</span>', $html);
        self::assertStringContainsString('</a>', $html);
        self::assertStringNotContainsString('WJPC 2024', $html);
    }

    public function testStandaloneWithoutShortcutFallsBackToName(): void
    {
        $html = $this->render([
            'competitionName' => 'Czech National Championship 2024',
            'competitionSlug' => 'czech-nationals-2024',
        ]);

        self::assertStringContainsString('<a href="/en/events/czech-nationals-2024">', $html);
        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> Czech National Championship 2024</span>', $html);
    }

    public function testStandaloneWithoutSlugRendersBadgeWithoutLink(): void
    {
        $html = $this->render([
            'competitionName' => 'Garage Puzzle Night',
            'competitionShortcut' => 'GPN',
        ]);

        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> GPN</span>', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('</a>', $html);
    }

    public function testEditionCombinesSeriesNameAndEditionNameAndLinksToEdition(): void
    {
        $html = $this->render([
            'competitionName' => 'EJJ #68 — February 2026',
            'competitionSlug' => 'ejj-68-february-2026',
            'competitionSeriesName' => 'Euro Jigsaw Jam',
            'competitionSeriesSlug' => 'euro-jigsaw-jam-series',
        ]);

        self::assertStringContainsString('<a href="/en/series/euro-jigsaw-jam-series/ejj-68-february-2026">', $html);
        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> Euro Jigsaw Jam · EJJ #68 — February 2026</span>', $html);
        self::assertStringNotContainsString('/en/events/', $html);
    }

    public function testEditionPrefersSeriesShortcut(): void
    {
        $html = $this->render([
            'competitionName' => 'EJJ #68 — February 2026',
            'competitionSlug' => 'ejj-68-february-2026',
            'competitionSeriesName' => 'Euro Jigsaw Jam',
            'competitionSeriesShortcut' => 'EJJ',
            'competitionSeriesSlug' => 'euro-jigsaw-jam-series',
        ]);

        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> EJJ · EJJ #68 — February 2026</span>', $html);
        self::assertStringNotContainsString('Euro Jigsaw Jam', $html);
    }

    public function testEditionNamedLikeItsSeriesShowsSeriesLabelOnly(): void
    {
        // Competitions converted to a series keep the series name on the edition.
        $html = $this->render([
            'competitionName' => 'euro jigsaw jam',
            'competitionSlug' => 'euro-jigsaw-jam',
            'competitionSeriesName' => 'Euro Jigsaw Jam',
            'competitionSeriesSlug' => 'euro-jigsaw-jam-series',
        ]);

        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> Euro Jigsaw Jam</span>', $html);
        self::assertStringNotContainsString('·', $html);
        self::assertStringContainsString('<a href="/en/series/euro-jigsaw-jam-series/euro-jigsaw-jam">', $html);
    }

    public function testEditionWithoutSeriesSlugNeverFallsBackToEventLink(): void
    {
        // A bare edition slug is only unique within its series, so without the series slug
        // there is no safe URL — render the badge unlinked rather than pointing at event_detail.
        $html = $this->render([
            'competitionName' => 'EJJ #68 — February 2026',
            'competitionSlug' => 'ejj-68-february-2026',
            'competitionSeriesName' => 'Euro Jigsaw Jam',
        ]);

        self::assertStringContainsString('<i class="bi bi-trophy-fill"></i> Euro Jigsaw Jam · EJJ #68 — February 2026</span>', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('/en/events/', $html);
    }

    public function testNoCompetitionRendersNothing(): void
    {
        self::assertSame('', $this->render([]));
    }
}
