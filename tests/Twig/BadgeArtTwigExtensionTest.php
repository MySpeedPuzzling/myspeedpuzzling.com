<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\BadgeArtMissing;
use SpeedPuzzling\Web\Services\Badges\BadgeArtManifest;
use SpeedPuzzling\Web\Twig\BadgeArtTwigExtension;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Translation\Loader\ArrayLoader as TranslationArrayLoader;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class BadgeArtTwigExtensionTest extends TestCase
{
    private const string VERSION = '3f9a1c0e';

    private string $manifestPath;

    protected function setUp(): void
    {
        $this->manifestPath = sys_get_temp_dir() . '/badge-art-manifest-' . bin2hex(random_bytes(6)) . '.json';

        $files = [];

        foreach (['puzzles_solved', 'streak'] as $type) {
            foreach (['t1', 't2', 't3', 't4', 't5'] as $variant) {
                $files += self::filesOf($type, $variant);
            }
        }

        $files += self::filesOf('supporter', 'single');

        self::writeManifest($this->manifestPath, ['version' => self::VERSION, 'files' => $files]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }
    }

    public function testTheUrlCarriesTheManifestVersion(): void
    {
        self::assertSame(
            '/img/badges/webp/144/puzzles_solved-t3.webp?v=' . self::VERSION,
            $this->extension()->badgeArtUrl(BadgeType::PuzzlesSolved, BadgeTier::Gold),
        );
    }

    public function testTypeAndTierMayBeGivenAsTheirValues(): void
    {
        self::assertSame(
            '/img/badges/png/96/streak-t2.png?v=' . self::VERSION,
            $this->extension()->badgeArtUrl('streak', 2, 'png', 96),
        );
    }

    public function testTheSvgHasNoSize(): void
    {
        self::assertSame(
            '/img/badges/svg/streak-t5.svg?v=' . self::VERSION,
            $this->extension()->badgeArtUrl(BadgeType::Streak, BadgeTier::Diamond, 'svg'),
        );
    }

    public function testEmailsGetAnAbsoluteUrl(): void
    {
        self::assertSame(
            'https://myspeedpuzzling.test/img/badges/png/96/puzzles_solved-t1.png?v=' . self::VERSION,
            $this->extension()->badgeArtUrl(BadgeType::PuzzlesSolved, BadgeTier::Bronze, 'png', 96, absolute: true),
        );
    }

    public function testASingleTierAchievementHasOneBadge(): void
    {
        $expected = '/img/badges/webp/144/supporter-single.webp?v=' . self::VERSION;

        self::assertSame($expected, $this->extension()->badgeArtUrl(BadgeType::Supporter, null));
        self::assertSame($expected, $this->extension()->badgeArtUrl('supporter', 0));
    }

    /**
     * @return iterable<string, array{0: BadgeType|string, 1: null|BadgeTier|int, 2?: string, 3?: int}>
     */
    public static function provideArtThatDoesNotExist(): iterable
    {
        yield 'not an achievement' => ['night_owl', 1];
        yield 'tiered achievement without a tier' => [BadgeType::PuzzlesSolved, null];
        yield 'single-tier achievement with a tier' => [BadgeType::Supporter, BadgeTier::Bronze];
        yield 'tier 6' => [BadgeType::Streak, 6];
        yield 'negative tier' => [BadgeType::Streak, -1];
        yield 'size that is not rendered' => [BadgeType::Streak, 1, 'webp', 100];
        yield 'PNG only for e-mails' => [BadgeType::Streak, 1, 'png', 480];
        yield 'unknown format' => [BadgeType::Streak, 1, 'gif', 96];
        yield 'achievement without art yet' => [BadgeType::Librarian, 1];
    }

    #[DataProvider('provideArtThatDoesNotExist')]
    public function testArtThatDoesNotExistThrowsInDebug(BadgeType|string $type, null|BadgeTier|int $tier, string $format = 'webp', int $px = 144): void
    {
        $this->expectException(BadgeArtMissing::class);

        $this->extension()->badgeArtUrl($type, $tier, $format, $px);
    }

    public function testProductionLeavesAMissingPictureOutAndLogsAWarning(): void
    {
        $handler = new TestHandler();
        $extension = $this->extension(debug: false, logger: new Logger('test', [$handler]));

        self::assertSame('', $extension->badgeArtUrl('night_owl', 1));
        self::assertSame('', $extension->badgeArt(BadgeType::PuzzlesSolved, null));
        self::assertCount(2, $handler->getRecords());
        self::assertTrue($handler->hasWarningThatContains('Badge art missing'));
    }

    public function testTheImageIsTheWebpAtTwiceTheDisplaySizeWithTheOneXFileInSrcset(): void
    {
        $v = self::VERSION;

        self::assertSame(
            '<img src="/img/badges/webp/144/puzzles_solved-t3.webp?v=' . $v . '"'
            . ' srcset="/img/badges/webp/96/puzzles_solved-t3.webp?v=' . $v . ' 1x, /img/badges/webp/144/puzzles_solved-t3.webp?v=' . $v . ' 2x"'
            . ' width="72" height="72" alt="Puzzle Explorer — Gold" loading="lazy" decoding="async" class="badge-art">',
            $this->extension()->badgeArt(BadgeType::PuzzlesSolved, BadgeTier::Gold),
        );
    }

    public function testASmallBadgeNeedsNoSrcsetWhenTwiceItsSizeIsTheSmallestFile(): void
    {
        $html = $this->extension()->badgeArt(BadgeType::Streak, 1, 48);

        self::assertStringContainsString('src="/img/badges/webp/96/streak-t1.webp?v=' . self::VERSION . '"', $html);
        self::assertStringNotContainsString('srcset', $html);
        self::assertStringContainsString('width="48" height="48"', $html);
    }

    public function testABigBadgeTakesTheNextBiggerFiles(): void
    {
        $html = $this->extension()->badgeArt(BadgeType::Streak, 5, 240);

        self::assertStringContainsString('srcset="/img/badges/webp/320/streak-t5.webp?v=' . self::VERSION . ' 1x, /img/badges/webp/480/streak-t5.webp?v=' . self::VERSION . ' 2x"', $html);
    }

    public function testTheSingleTierAltIsTheAchievementOnly(): void
    {
        self::assertStringContainsString('alt="Supporter"', $this->extension()->badgeArt(BadgeType::Supporter, null));
    }

    public function testLockedBadgeAndExtraAttributes(): void
    {
        $html = $this->extension()->badgeArt(BadgeType::Streak, BadgeTier::Silver, 96, locked: true, attrs: [
            'class' => 'mb-3',
            'alt' => '',
            'loading' => 'eager',
            'title' => 'Say "hi" <b>',
            'decoding' => null,
            'data-hidden' => true,
        ]);

        self::assertStringContainsString('class="badge-art badge-art-locked mb-3"', $html);
        self::assertStringContainsString('alt=""', $html);
        self::assertStringContainsString('loading="eager"', $html);
        self::assertStringContainsString('title="Say &quot;hi&quot; &lt;b&gt;"', $html);
        self::assertStringNotContainsString('decoding', $html);
        self::assertStringContainsString(' data-hidden', $html);
    }

    public function testAnInvalidAttributeNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->extension()->badgeArt(BadgeType::Streak, 1, attrs: ['onload x="alert(1)"' => '1']);
    }

    public function testAMissingManifestThrowsInDebug(): void
    {
        unlink($this->manifestPath);

        $this->expectException(BadgeArtMissing::class);
        $this->expectExceptionMessage('does not exist');

        $this->extension()->badgeArtUrl(BadgeType::Streak, 1);
    }

    public function testManifestPathsWrittenFromTheProjectRootAreUnderstood(): void
    {
        self::writeManifest($this->manifestPath, [
            'version' => 'abc',
            'files' => ['public/img/badges/webp/144/streak-t4.webp' => 'x', 'public/img/badges/webp/96/streak-t4.webp' => 'x'],
        ]);

        self::assertSame('/img/badges/webp/144/streak-t4.webp?v=abc', $this->extension()->badgeArtUrl(BadgeType::Streak, 4));
    }

    public function testTwigNamedArguments(): void
    {
        $twig = new Environment(new ArrayLoader([
            'badge' => "{{ badge_art(type, tier, 48, locked = true, attrs = {alt: ''}) }}|{{ badge_art(type, tier, display_px = 96) }}|{{ badge_art_url('supporter', null, 'png', 96, absolute = true) }}",
        ]), ['strict_variables' => true]);
        $twig->addExtension($this->extension());

        [$locked, $big, $url] = explode('|', $twig->render('badge', ['type' => BadgeType::Streak, 'tier' => BadgeTier::Platinum]));

        self::assertStringContainsString('class="badge-art badge-art-locked"', $locked);
        self::assertStringContainsString('alt=""', $locked);
        self::assertStringContainsString('width="96"', $big);
        self::assertStringContainsString('src="/img/badges/webp/192/streak-t4.webp', $big);
        self::assertSame('https://myspeedpuzzling.test/img/badges/png/96/supporter-single.png?v=' . self::VERSION, $url);
    }

    private function extension(bool $debug = true, null|LoggerInterface $logger = null): BadgeArtTwigExtension
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new TranslationArrayLoader());
        $translator->addResource('array', [
            'badges.badge.puzzles_solved' => 'Puzzle Explorer',
            'badges.badge.streak' => 'On Fire',
            'badges.badge.supporter' => 'Supporter',
            'badges.tier.gold' => 'Gold',
        ], 'en');

        return new BadgeArtTwigExtension(
            new BadgeArtManifest($this->manifestPath, $debug),
            new Packages(new PathPackage('/', new EmptyVersionStrategy())),
            new UrlHelper(new RequestStack(), new RequestContext(host: 'myspeedpuzzling.test', scheme: 'https')),
            $translator,
            $logger ?? new NullLogger(),
            $debug,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function filesOf(string $type, string $variant): array
    {
        $files = ["svg/{$type}-{$variant}.svg" => 'sha256'];

        foreach ([96, 144, 192, 320, 480] as $px) {
            $files["webp/{$px}/{$type}-{$variant}.webp"] = 'sha256';
        }

        foreach ([96, 144] as $px) {
            $files["png/{$px}/{$type}-{$variant}.png"] = 'sha256';
        }

        return $files;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function writeManifest(string $path, array $manifest): void
    {
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
