<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DatePrecision;

use SpeedPuzzling\Web\Tests\MessageHandler\DataImport\ImportAddingScenario;
use SpeedPuzzling\Web\Tests\PatTestHelper;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A result that knows only its month or year is shown as that (docs/features/data-import/date-precision.md
 * "Showing"): "Mar 2021" and "2019" on the profile results and the result detail - never 01.03.2021 - and the API says
 * how much of `finished_at` is known.
 */
final class DatePrecisionPagesTest extends WebTestCase
{
    use ImportAddingScenario;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
    }

    public function testTheProfileTheResultDetailAndTheApiShowTheMonthAndTheYear(): void
    {
        $player = $this->seedPairingPlayer('precision-pages');
        $playerId = $player->id->toString();
        $brand = $this->seedBrand('Zqpages');
        $this->seedCataloguePuzzle('Zq Pages Month', 500, $brand);
        $this->seedCataloguePuzzle('Zq Pages Year', 500, $brand);
        $this->seedCataloguePuzzle('Zq Pages Day', 500, $brand);

        [$import, $sheet] = $this->importOf($player);
        $this->resultRow($import, $sheet, 'Zq Pages Month', 500, 'Zqpages', '2021-03', 3600);
        $this->resultRow($import, $sheet, 'Zq Pages Year', 500, 'Zqpages', '2019', 3700);
        $this->resultRow($import, $sheet, 'Zq Pages Day', 500, 'Zqpages', '2021-03-15', 3800);
        $this->pair($import);
        $this->addAndWork($import, $player);

        $timeIds = [];

        foreach ($this->resultsOf($player) as $result) {
            self::assertIsInt($result['seconds_to_solve']);
            self::assertIsString($result['id']);
            $timeIds[$result['seconds_to_solve']] = $result['id'];
        }

        TestingLogin::asPlayer($this->browser, $playerId);

        // The profile results
        $crawler = $this->browser->request('GET', '/en/player-profile/' . $playerId);
        self::assertResponseIsSuccessful();
        $dates = $crawler->filter('time.ps-date')->each(static fn ($node): array => [trim($node->text()), $node->attr('datetime')]);
        sort($dates);
        self::assertSame([['15.03.2021', '2021-03-15'], ['2019', '2019'], ['Mar 2021', '2021-03']], $dates);
        $page = (string) $this->browser->getResponse()->getContent();
        self::assertStringNotContainsString('01.03.2021', $page);
        self::assertStringNotContainsString('01.01.2019', $page);

        // The result detail
        foreach ([3600 => 'Mar 2021', 3700 => '2019', 3800 => '15.03.2021'] as $seconds => $shown) {
            $this->browser->request('GET', '/en/result/' . $timeIds[$seconds]);
            self::assertResponseIsSuccessful();
            $detail = (string) $this->browser->getResponse()->getContent();
            self::assertStringContainsString($shown, $detail, (string) $seconds);
            self::assertStringNotContainsString('01.03.2021', $detail);
            self::assertStringNotContainsString('01.01.2019', $detail);
        }

        // API v1: `finished_at` keeps its value, `date_precision` says what it is - an added field
        $token = PatTestHelper::createToken($this->browser, $playerId);
        PatTestHelper::addBearerToken($this->browser, $token);
        $this->browser->request('GET', '/api/v1/me/results');
        self::assertResponseIsSuccessful();

        /** @var array{results: list<array{time_seconds: int, finished_at: string, date_precision: string}>} $response */
        $response = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $api = [];

        foreach ($response['results'] as $result) {
            $api[$result['time_seconds']] = [substr($result['finished_at'], 0, 10), $result['date_precision']];
        }

        ksort($api);
        self::assertSame([3600 => ['2021-03-01', 'month'], 3700 => ['2019-01-01', 'year'], 3800 => ['2021-03-15', 'day']], $api);
    }
}
