<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionPicker;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S1 - typing finds editions (docs/features/events-page/high-frequency-series.md): the JSON TomSelect loads from two
 * typed characters.
 */
final class CompetitionPickerEditionsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string URL = '/en/competition-picker/editions';

    public function testAnonymousVisitorIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::URL . '?q=jam');

        $this->assertResponseRedirects();
    }

    public function testFewerThanTwoCharactersAnswerNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertSame(['options' => [], 'optgroups' => []], $this->json($browser, ''));
        self::assertSame(['options' => [], 'optgroups' => []], $this->json($browser, 'J'));
    }

    public function testMatchingEditionsUnderTheirSeries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $scenario = new SeriesEditionScenario(self::getContainer());
        $today = self::getContainer()->get(ClockInterface::class)->now();

        $seriesId = $scenario->series('Lantern Weekly Jam');
        $no153 = $scenario->edition($seriesId, 'Jam No. 153', $today->modify('-9 days')->format('Y-m-d'));
        $no154 = $scenario->edition($seriesId, 'Jam No. 154', $today->modify('-2 days')->format('Y-m-d'));
        // H12 scenario 9: a draft edition is never found
        $scenario->edition($seriesId, 'Jam No. 155', $today->modify('+1 day')->format('Y-m-d'), draft: true);

        $json = $this->json($browser, 'No. 15');

        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame(['edition:' . $no154, 'edition:' . $no153], array_column($json['options'], 'value'));
        self::assertSame([['value' => $seriesId, 'label' => 'Lantern Weekly Jam']], $json['optgroups']);

        $option = $json['options'][0];
        self::assertSame($seriesId, $option['optgroup']);
        self::assertIsString($option['text']);
        self::assertStringContainsString('Jam No. 154', $option['text']);
        self::assertStringContainsString('competition-option', $option['text']);
        self::assertIsString($option['keywords']);
        self::assertStringContainsString('Lantern Weekly Jam', $option['keywords']);
        self::assertStringNotContainsString('Jam No. 155', (string) $browser->getResponse()->getContent());
    }

    public function testOneStatementOnTopOfTheSignedInOverhead(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $overhead = $this->measure($browser, self::URL . '?q=J');
        $search = $this->measure($browser, self::URL . '?q=' . rawurlencode('No. 15'));

        self::assertSame($overhead + 1, $search);
    }

    /**
     * @return array{options: list<array<string, mixed>>, optgroups: list<array<string, mixed>>}
     */
    private function json(KernelBrowser $browser, string $query): array
    {
        $browser->request('GET', self::URL . '?q=' . rawurlencode($query));
        self::assertResponseIsSuccessful();

        /** @var array{options: list<array<string, mixed>>, optgroups: list<array<string, mixed>>} $json */
        $json = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $json;
    }

    private function measure(KernelBrowser $browser, string $url): int
    {
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
