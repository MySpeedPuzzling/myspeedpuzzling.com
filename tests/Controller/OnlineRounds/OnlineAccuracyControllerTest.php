<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OnlineRounds;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Controller\OnlineRounds\OnlineAccuracyController;
use SpeedPuzzling\Web\Message\ConfirmOnlineResult;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\OnlineReviewSeeding;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\OnlineConfirmedSource;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The accuracy report of an online round, Phase 1 (online_accuracy, docs/features/online-competitions/README.md §5.9,
 * stream C2): the reviewer's adjustment (confirmed − tap) per participant and its distribution, as a page and as CSV -
 * every CSV cell through SpreadsheetSafeValue, numbers staying numbers.
 */
final class OnlineAccuracyControllerTest extends WebTestCase
{
    use OnlineReviewSeeding;
    use OverridesFeatureFlagEnv;

    private const string FLAG = 'ONLINE_ROUNDS_FOR_ORGANISERS';
    private const string COMPETITION = OnlineRoundsFixture::COMPETITION_ONLINE_TEST_CUP;
    private const string ROUND = OnlineRoundsFixture::ROUND_ONLINE_SOLO;
    private const string PAGE = '/en/online-rounds/' . self::ROUND . '/accuracy';
    private const string FORMULA_NAME = '=HYPERLINK("https://evil.example","Prize")';

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testTheReportShowsEachAdjustmentAndTheirSpread(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $this->seedConfirmedRound();

        $crawler = $browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame('3', $crawler->filter('[data-accuracy-count]')->attr('data-accuracy-count'));
        self::assertSame('3 confirmed finishes', $crawler->filter('[data-accuracy-count]')->text());
        // −1200, 0, +400 ms: the median is the tap itself, the middle half spans 0.8 s
        self::assertSame('0.0 s', $crawler->filter('[data-accuracy-median]')->text());
        self::assertSame('0.8 s', $crawler->filter('[data-accuracy-iqr]')->text());

        // In finishing order, unconfirmed Arthur last
        self::assertSame(['Olivia Online', self::FORMULA_NAME, 'Paula Pace', 'Arthur Anonymous'], $crawler->filter('[data-accuracy-row] td:first-child')->each(static fn ($cell): string => $cell->text()));
        self::assertSame(['−1.2 s', '+0.4 s', '0.0 s', ''], $crawler->filter('[data-accuracy-adjustment]')->each(static fn ($cell): string => $cell->text()));
        self::assertCount(1, $crawler->filter('[data-accuracy-csv][href="' . self::PAGE . '?format=csv"]'));
    }

    public function testTheCsvIsFormulaSafeAndItsNumbersStayNumbers(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_ADMIN);
        $startMs = $this->seedConfirmedRound();

        $browser->request('GET', self::PAGE . '?format=csv');

        self::assertResponseIsSuccessful();
        $response = $browser->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment;', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('accuracy.csv', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $content = (string) $response->getContent();
        self::assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = array_map(
            static fn (string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", substr($content, 3)), static fn (string $line): bool => $line !== '')),
        );

        self::assertSame(OnlineAccuracyController::CSV_COLUMNS, $lines[0]);
        $rows = array_column(array_slice($lines, 1), null, 0);
        self::assertCount(4, $rows);

        // Text a spreadsheet would run as a formula gets the apostrophe
        self::assertArrayHasKey("'" . self::FORMULA_NAME, $rows);
        $formula = $rows["'" . self::FORMULA_NAME];
        self::assertSame('confirmed', $formula[1]);
        self::assertSame((string) ($startMs + 2_000_000), $formula[2]);
        self::assertSame('400', $formula[6]);
        self::assertSame('reviewer', $formula[7]);

        // A negative adjustment is a number, not text with an apostrophe
        self::assertSame('-1200', $rows['Olivia Online'][6]);
        self::assertSame('1000000', $rows['Olivia Online'][3]);
        self::assertSame('998800', $rows['Olivia Online'][5]);
        self::assertSame('3500', $rows['Olivia Online'][8]);
        self::assertSame('998', $rows['Olivia Online'][9]);
        self::assertSame(['Arthur Anonymous', 'no_finish', '', '', '', '', '', '', '', '', ''], $rows['Arthur Anonymous']);
    }

    public function testOnlyTheEventsOrganisersWithTheFeatureSeeIt(): void
    {
        $this->overrideFeatureFlagEnv(self::FLAG, false);
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->insert('competition_maintainer', ['competition_id' => self::COMPETITION, 'player_id' => PlayerFixture::PLAYER_WITH_STRIPE]);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
        $browser->request('GET', self::PAGE . '?format=csv');
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Olivia confirmed 1.2 s before her tap (a frame), a participant with a formula for a name 0.4 s after it, Paula at
     * her tap; Arthur's phone never sent anything.
     *
     * @return int the round's start
     */
    private function seedConfirmedRound(): int
    {
        $startMs = $this->startOnlineRound();

        $olivia = $this->onlineEntryOf(OnlineRoundsFixture::ENTRY_CONNECTED);
        $tapMs = $startMs + 1_000_000;
        $tapId = $this->finishTap($olivia, $tapMs, $tapMs + 3_500);
        $this->evidence($olivia, OnlineMediaKind::Burst, $tapMs, $tapId, self::burstManifest($tapMs));
        $this->confirmResult(OnlineRoundsFixture::PARTICIPANT_CONNECTED, $tapMs - 1_200, OnlineConfirmedSource::Reviewer);

        $formulaEntry = $this->enterParticipant(self::FORMULA_NAME);
        $formula = $this->onlineEntryOf($formulaEntry->id->toString());
        $formulaTapMs = $startMs + 2_000_000;
        $formulaTapId = $this->finishTap($formula, $formulaTapMs);
        $this->evidence($formula, OnlineMediaKind::Burst, $formulaTapMs, $formulaTapId, self::burstManifest($formulaTapMs));
        $this->confirmResult($formulaEntry->participant->id->toString(), $formulaTapMs + 400, OnlineConfirmedSource::Reviewer);

        $paulaEntry = $this->enterParticipant('Paula Pace');
        $paula = $this->onlineEntryOf($paulaEntry->id->toString());
        $paulaTapMs = $startMs + 2_500_000;
        $this->finishTap($paula, $paulaTapMs);
        $this->confirmResult($paulaEntry->participant->id->toString(), $paulaTapMs, OnlineConfirmedSource::Button);

        return $startMs;
    }

    private function confirmResult(string $participantId, int $momentMs, OnlineConfirmedSource $source): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ConfirmOnlineResult(
            self::COMPETITION,
            self::ROUND,
            $participantId,
            $momentMs,
            $source,
            null,
            PlayerFixture::PLAYER_ADMIN,
        ));
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);
        $browser->disableReboot();

        return $browser;
    }
}
