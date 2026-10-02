<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\DetectDuplicatePuzzleSignals;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Possible duplicate puzzles" on /admin/duplicate-results (docs/features/duplicate-results.md, Layer 4).
 */
final class DuplicatePuzzleSignalsControllerTest extends WebTestCase
{
    public function testAdminSeesTheSignalWithBothPuzzles(): void
    {
        [$browser, $signalId] = $this->adminWithSignal();

        $crawler = $browser->request('GET', '/admin/duplicate-results');

        $this->assertResponseIsSuccessful();
        $section = $crawler->filter('#puzzle-signals');
        self::assertStringContainsString('Possible duplicate puzzles', $section->text());
        self::assertCount(1, $section->filter('a[href="/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04 . '"]'));
        self::assertCount(1, $section->filter('a[href="/en/puzzle/' . PuzzleFixture::PUZZLE_1000_05 . '"]'));
        self::assertStringContainsString('2 matching results by 1 person', $section->text());
        self::assertCount(1, $section->filter('form[action="/admin/duplicate-results/puzzle-signals/' . $signalId . '/propose-merge"]'));
        self::assertCount(1, $section->filter('form[action="/admin/duplicate-results/puzzle-signals/' . $signalId . '/dismiss"]'));
    }

    public function testStrongestSignalFirstWeakOnesOnRequest(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $container = $browser->getContainer();
        $database = $container->get(Connection::class);
        // Same EAN in two languages - beats a similar name alone; numbered parts of one set are weak
        $this->signalOn($browser, PuzzleFixture::PUZZLE_1500_01, 'Formule 1 Monaco', PuzzleFixture::PUZZLE_1500_02, 'Formel 1 Monaco', '02:22:22');
        $this->signalOn($browser, PuzzleFixture::PUZZLE_1000_04, 'Soft Cans', PuzzleFixture::PUZZLE_1000_05, 'Lata sobre lata', '01:11:11', '8412668184473');
        $this->signalOn($browser, PuzzleFixture::PUZZLE_500_04, 'Advent Calendar 4', PuzzleFixture::PUZZLE_500_05, 'Advent Calendar 5', '00:33:33');
        $container->get(MessageBusInterface::class)->dispatch(new DetectDuplicatePuzzleSignals());
        $weakSignals = $database->fetchOne("SELECT COUNT(*) FROM duplicate_puzzle_signal WHERE status = 'open' AND weak");
        self::assertIsInt($weakSignals);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $section = $browser->request('GET', '/admin/duplicate-results')->filter('#puzzle-signals');

        $html = (string) $section->html();
        $sameEan = strpos($html, '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04);
        $similarName = strpos($html, '/en/puzzle/' . PuzzleFixture::PUZZLE_1500_01);
        self::assertNotFalse($sameEan);
        self::assertNotFalse($similarName);
        self::assertLessThan($similarName, $sameEan);
        self::assertStringNotContainsString('/en/puzzle/' . PuzzleFixture::PUZZLE_500_04, $html);
        self::assertStringContainsString('same EAN', $this->cardOf($section, PuzzleFixture::PUZZLE_1000_04)->text());
        self::assertMatchesRegularExpression('/similar name 0\.6\d/', $this->cardOf($section, PuzzleFixture::PUZZLE_1500_01)->text());

        $toggle = $section->filter('[data-test="signal-strength"] a');
        self::assertSame('Show weak signals (' . $weakSignals . ')', trim($toggle->text()));

        $section = $browser->request('GET', (string) $toggle->attr('href'))->filter('#puzzle-signals');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('parts of one set', $this->cardOf($section, PuzzleFixture::PUZZLE_500_04)->text());
        self::assertCount(0, $section->filter('a[href="/en/puzzle/' . PuzzleFixture::PUZZLE_1000_04 . '"]'));
        self::assertStringContainsString('Back to strong signals', $section->filter('[data-test="signal-strength"]')->text());
    }

    public function testProposeMergeOpensTheMergeRequest(): void
    {
        [$browser, $signalId] = $this->adminWithSignal();
        $crawler = $browser->request('GET', '/admin/duplicate-results');

        $this->post($browser, $crawler, $signalId, 'propose-merge');

        $mergeRequestId = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT merge_request_id FROM duplicate_puzzle_signal WHERE id = :id',
            ['id' => $signalId],
        );
        self::assertIsString($mergeRequestId);
        $location = (string) $browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith('/admin/puzzle-merge-requests/' . $mergeRequestId . '?', $location);

        $browser->request('GET', $location);
        $this->assertResponseIsSuccessful();

        // No longer listed
        $crawler = $browser->request('GET', '/admin/duplicate-results');
        self::assertStringContainsString('No open signals.', $crawler->filter('#puzzle-signals')->text());
    }

    public function testDismiss(): void
    {
        [$browser, $signalId] = $this->adminWithSignal();
        $crawler = $browser->request('GET', '/admin/duplicate-results');

        $this->post($browser, $crawler, $signalId, 'dismiss');

        $this->assertResponseRedirects('/admin/duplicate-results#puzzle-signals');
        self::assertSame('dismissed', $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT status FROM duplicate_puzzle_signal WHERE id = :id',
            ['id' => $signalId],
        ));
    }

    public function testATokenIsRequired(): void
    {
        [$browser, $signalId] = $this->adminWithSignal();

        $browser->request('POST', '/admin/duplicate-results/puzzle-signals/' . $signalId . '/dismiss', ['_token' => 'forged']);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testPlayersAndGuestsHaveNoBusinessHere(): void
    {
        $browser = self::createClient();
        $url = '/admin/duplicate-results/puzzle-signals/' . Uuid::uuid7()->toString() . '/propose-merge';

        $browser->request('POST', $url);
        $this->assertResponseRedirects();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('POST', $url);
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * @return array{KernelBrowser, string}
     */
    private function adminWithSignal(): array
    {
        $browser = self::createClient();
        $browser->disableReboot();
        $container = $browser->getContainer();
        // A strong signal: the fixture names ("Puzzle 9", "Puzzle 10") would read as numbered parts of one set
        $this->signalOn($browser, PuzzleFixture::PUZZLE_1000_04, 'Formule 1 Monaco', PuzzleFixture::PUZZLE_1000_05, 'Formel 1 Monaco', '01:11:11');
        $container->get(MessageBusInterface::class)->dispatch(new DetectDuplicatePuzzleSignals());

        $signalId = $container->get(Connection::class)->fetchOne(
            'SELECT id FROM duplicate_puzzle_signal WHERE puzzle_a_id = :id',
            ['id' => PuzzleFixture::PUZZLE_1000_04],
        );
        self::assertIsString($signalId);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return [$browser, $signalId];
    }

    /**
     * The same time on the same day on both puzzles, renamed (and with one EAN) first.
     */
    private function signalOn(KernelBrowser $browser, string $puzzleAId, string $nameA, string $puzzleBId, string $nameB, string $time, null|string $ean = null): void
    {
        $container = $browser->getContainer();
        $messageBus = $container->get(MessageBusInterface::class);
        $day = $container->get(ClockInterface::class)->now()->modify('-3 days');

        foreach ([$puzzleAId => $nameA, $puzzleBId => $nameB] as $puzzleId => $name) {
            $container->get(Connection::class)->executeStatement(
                'UPDATE puzzle SET name = :name, alternative_name = NULL, ean = COALESCE(:ean, ean) WHERE id = :id',
                ['name' => $name, 'ean' => $ean, 'id' => $puzzleId],
            );

            $messageBus->dispatch(new AddPuzzleSolvingTime(
                timeId: Uuid::uuid7(),
                userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
                puzzleId: $puzzleId,
                competitionId: null,
                time: $time,
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [],
                finishedAt: $day,
                firstAttempt: false,
                unboxed: false,
            ));
        }
    }

    private function cardOf(Crawler $section, string $puzzleId): Crawler
    {
        return $section->filter('.card')->reduce(
            static fn (Crawler $card): bool => $card->filter('a[href="/en/puzzle/' . $puzzleId . '"]')->count() > 0,
        );
    }

    private function post(KernelBrowser $browser, Crawler $crawler, string $signalId, string $action): void
    {
        $form = $crawler->filter('form[action="/admin/duplicate-results/puzzle-signals/' . $signalId . '/' . $action . '"]');

        $browser->request('POST', (string) $form->attr('action'), [
            '_token' => (string) $form->filter('input[name="_token"]')->attr('value'),
        ]);

        $this->assertResponseRedirects();
    }
}
