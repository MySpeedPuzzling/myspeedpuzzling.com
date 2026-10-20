<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

final class RankChipTemplateTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(LocaleSwitcher::class)->setLocale('en');
        parent::tearDown();
    }

    private function render(int $rank, int $total, string $locale = 'en'): string
    {
        // Translator, intl formatters and \Locale alike
        self::getContainer()->get(LocaleSwitcher::class)->setLocale($locale);

        $html = $this->twig->render('_rank_chip.html.twig', ['rank' => $rank, 'total' => $total]);

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    public function testTheShareFollowsTheRank(): void
    {
        self::assertSame('#204 of 1391 top 14.7%', $this->render(204, 1391));
    }

    public function testTwoDecimalsUnderOnePercent(): void
    {
        self::assertSame('#3 of 1391 top 0.22%', $this->render(3, 1391));
    }

    public function testTheFirstPlaceIsTheFastest(): void
    {
        self::assertSame('#1 of 1391 fastest', $this->render(1, 1391));
    }

    public function testASmallListShowsTheRankAlone(): void
    {
        self::assertSame('#2 of 9', $this->render(2, 9));
    }

    public function testThePercentFollowsTheLocale(): void
    {
        self::assertSame('#204 z 1391 top 14,7 %', $this->render(204, 1391, 'cs'));
    }

    public function testEveryLocaleWritesItsOwnPercent(): void
    {
        self::assertSame('#204 von 1391 Top 14,7 %', $this->render(204, 1391, 'de'));
        self::assertSame('1391中 #204 上位14.7%', $this->render(204, 1391, 'ja'));
    }

    public function testTheRecapPlaceRowShowsTheShare(): void
    {
        self::assertStringContainsString('top 14.7%', $this->renderRecap(204, 1391));
        self::assertStringContainsString('The fastest time on this puzzle', $this->renderRecap(1, 1391));
        self::assertStringNotContainsString('top', $this->renderRecap(2, 9));
    }

    private function renderRecap(int $rank, int $total): string
    {
        $html = $this->twig->render('added_time_recap/_place_row.html.twig', [
            'ranking' => ['rank' => $rank, 'totalPlayers' => $total],
            'solved_puzzle' => ['puzzleId' => '018d0000-0000-0000-0000-000000000000'],
        ]);

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    public function testTheBarFillsWithTheShareBehind(): void
    {
        $html = $this->twig->render('_rank_chip.html.twig', ['rank' => 6, 'total' => 11]);

        self::assertStringContainsString('style="width: 50%"', $html);
    }
}
