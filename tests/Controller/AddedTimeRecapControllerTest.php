<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\SolvingTimePrediction;
use SpeedPuzzling\Web\Value\TimePredictionSource;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddedTimeRecapControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
    }

    public function testCollectionCtaIsHiddenWhenPuzzleIsAlreadyInCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#recap-collection-cta');
    }

    public function testCollectionCtaIsHiddenWhenPuzzleIsBorrowed(): void
    {
        $browser = self::createClient();

        // TIME_42: PLAYER_WITH_STRIPE solved a puzzle they only borrowed
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_42);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#recap-collection-cta');
    }

    public function testNonMemberGetsOneClickFormForSystemCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleIntelligenceFixture::INTEL_TIME_13);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#recap-collection-cta form button[type=submit]');
        $this->assertSelectorNotExists('#recap-collection-cta a[data-turbo-frame="modal-frame"]');

        // The one-click form must really add the puzzle - its field names come from the form type
        $browser->submit($crawler->filter('#recap-collection-cta form')->form());
        $this->assertResponseRedirects();

        $browser->request('GET', '/en/time-added/' . PuzzleIntelligenceFixture::INTEL_TIME_13);
        $this->assertSelectorNotExists('#recap-collection-cta');
    }

    public function testMemberGetsCollectionPickerModalLink(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleIntelligenceFixture::INTEL_TIME_11);

        $this->assertResponseIsSuccessful();

        $link = $crawler->filter('#recap-collection-cta a[data-turbo-frame="modal-frame"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('context=recap', (string) $link->attr('href'));
        $this->assertSelectorNotExists('#recap-collection-cta form');
    }

    /**
     * @return iterable<string, array{TimePredictionResult, non-empty-string, non-empty-string, non-empty-string, int}>
     */
    public static function storedWindows(): iterable
    {
        // TIME_42 = 7000 s (1:56:40), PLAYER_WITH_STRIPE, solo
        yield 'inside the shown window' => [
            new TimePredictionResult(7100, 6800, 7400, 1.0),
            '.prediction-outcome-inside',
            'Inside your likely window – only half of solves do',
            'Likely 1h 53min – 2h 3min · half of solves land inside',
            3,
        ];
        yield 'a first try faster than the window is the player\'s margin' => [
            new TimePredictionResult(7500, 7200, 7800, 1.0),
            '.prediction-outcome-faster',
            'Your margin: 3 min faster than likely',
            'Likely 2h – 2h 10min · half of solves land inside',
            3,
        ];
        yield 'a repeat slower than the window: side and margin' => [
            new TimePredictionResult(6250, 6000, 6500, 1.0, isPersonalized: true, personalSolveCount: 1, predictedAttemptNumber: 2, lastTimeSeconds: 6500),
            '.prediction-outcome-slower',
            '9 min slower than likely',
            'Likely 1h 40min – 1h 48min · half of solves land inside',
            3,
        ];
        yield 'a repeat faster than the window: side and margin, no "your margin"' => [
            new TimePredictionResult(7500, 7200, 7800, 1.0, isPersonalized: true, personalSolveCount: 1, predictedAttemptNumber: 2, lastTimeSeconds: 7600),
            '.prediction-outcome-faster',
            '3 min faster than likely',
            'Likely 2h – 2h 10min · half of solves land inside',
            3,
        ];
        yield 'a first try slower than the window: side and margin' => [
            new TimePredictionResult(6250, 6000, 6500, 1.0),
            '.prediction-outcome-slower',
            '9 min slower than likely',
            'Likely 1h 40min – 1h 48min · half of solves land inside',
            3,
        ];
        // Stored by a model before the calibrated windows (v2): the same rounding, no "half of solves" claim
        yield 'an uncalibrated window: inside, without the half claim' => [
            new TimePredictionResult(7100, 6800, 7400, 1.0),
            '.prediction-outcome-inside',
            'Inside your likely window',
            'Likely 1h 53min – 2h 3min',
            2,
        ];
        yield 'an uncalibrated window: a miss is still side and margin' => [
            new TimePredictionResult(6250, 6000, 6500, 1.0, isPersonalized: true, personalSolveCount: 1, predictedAttemptNumber: 2, lastTimeSeconds: 6500),
            '.prediction-outcome-slower',
            '9 min slower than likely',
            'Likely 1h 40min – 1h 48min',
            2,
        ];
    }

    /**
     * @param non-empty-string $selector
     * @param non-empty-string $outcome
     * @param non-empty-string $window
     */
    #[DataProvider('storedWindows')]
    public function testOutcomeIsReadAgainstTheShownLikelyWindow(TimePredictionResult $result, string $selector, string $outcome, string $window, int $modelVersion): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $time = $entityManager->find(PuzzleSolvingTime::class, PuzzleSolvingTimeFixture::TIME_42);
        self::assertNotNull($time);
        $time->recordPrediction(SolvingTimePrediction::predicted($result, TimePredictionSource::Live, $modelVersion), new DateTimeImmutable('2026-10-01 12:00:00'));
        $entityManager->flush();

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_42);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.prediction-outcome'));
        self::assertSame($outcome, $crawler->filter($selector)->text());
        self::assertStringStartsWith($window, $crawler->filter('.prediction-window')->text());

        if ($modelVersion < TimePredictionCalculator::FIRST_CALIBRATED_WINDOW_MODEL_VERSION) {
            self::assertStringNotContainsString('half of solves', (string) $browser->getResponse()->getContent());
        }
        self::assertStringNotContainsString('than predicted', (string) $browser->getResponse()->getContent());
    }

    /**
     * After an edit the recap shows the edited card: its URL changes with what the card shows, so neither the
     * browser nor the service worker can show the card of before (docs/features/result-share-image.md)
     */
    public function testTheShareCardsAddressChangesWithWhatItShows(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);
        $this->assertResponseIsSuccessful();
        $before = $crawler->filter('img[src*="/result-image/"]')->attr('src');
        self::assertIsString($before);
        self::assertMatchesRegularExpression('#^/result-image/' . PuzzleSolvingTimeFixture::TIME_01 . '\?v=[0-9a-f]{16}$#', $before);
        self::assertSame($before, $crawler->filter('[data-share-image-image-url-value]')->attr('data-share-image-image-url-value'));

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);
        self::assertSame($before, $crawler->filter('img[src*="/result-image/"]')->attr('src'), 'the same card, the same address');

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'UPDATE puzzle_solving_time SET seconds_to_solve = seconds_to_solve + 61 WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);
        self::assertNotSame($before, $crawler->filter('img[src*="/result-image/"]')->attr('src'));
    }
}
