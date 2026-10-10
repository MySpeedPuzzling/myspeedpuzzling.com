<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use SpeedPuzzling\Web\Services\Email\EmailHtmlToTextConverter;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The "you earned achievements" e-mail shows each badge as a 48 px PNG (96 px file) at an absolute URL, with the
 * achievement and tier as text beside it - said once, also in the plain-text part.
 */
final class BadgesEarnedEmailRenderingTest extends KernelTestCase
{
    public function testEveryEarnedAchievementShowsItsBadge(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('~<img src="https?://[^"/]+/img/badges/png/96/puzzles_solved-t3\.png\?v=[^"]+" alt="" width="48" height="48"~', $html);
        self::assertMatchesRegularExpression('~<img src="https?://[^"/]+/img/badges/png/96/supporter-single\.png\?v=[^"]+"~', $html);
        self::assertStringContainsString('Puzzle Explorer', $html);
        self::assertStringContainsString('Gold', $html);
    }

    public function testThePlainTextPartNamesEachAchievementOnce(): void
    {
        $text = (new EmailHtmlToTextConverter())->convert($this->render(), 'utf-8');

        self::assertSame(1, substr_count($text, 'Puzzle Explorer'));
        self::assertSame(1, substr_count($text, 'Early Adopter'));
    }

    private function render(): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);

        return $twig->render('emails/badges_earned.html.twig', [
            'badges' => [
                ['type' => BadgeType::PuzzlesSolved, 'tier' => BadgeTier::Gold],
                ['type' => BadgeType::Supporter, 'tier' => null],
            ],
            'locale' => 'en',
        ]);
    }
}
