<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\GoldenFile;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Baseline pin of the competition toolkit (R-review-and-plan.md §2.9, package A5): the public round page of rounds that
 * use no toolkit format (`total`), byte for byte - published official results for a guest and for an entrant who is
 * offered "Add to my profile", a published pair round, and an unpublished round. Pick rounds' per-puzzle chips and
 * per-puzzle "Add to my profile" are opt-in: a `total` round must stay exactly this.
 */
final class OfficialRoundResultsGoldenTest extends WebTestCase
{
    use GoldenFile;

    /**
     * @return iterable<string, array{string, null|string, bool}>
     */
    public static function pages(): iterable
    {
        yield 'published, guest' => ['group-a', null, false];
        yield 'published, the entrant offered Add to my profile' => ['group-a', PlayerFixture::PLAYER_ADMIN, false];
        yield 'published pairs, guest' => ['pairs', null, true];
        yield 'unpublished, guest' => ['group-b', null, false];
    }

    #[DataProvider('pages')]
    public function testTheRoundPageOfAnUntouchedRoundIsUnchanged(string $round, null|string $viewer, bool $publishPairs): void
    {
        $browser = self::createClient();

        if ($publishPairs) {
            self::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE competition_round SET results_published_at = ' . TestClock::SQL_NOW . ' WHERE id = :id',
                ['id' => OfficialResultsFixture::ROUND_PAIRS],
            );
        }

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        $browser->request('GET', '/en/events/results-cup/results/' . $round);

        self::assertResponseIsSuccessful();
        self::assertMatchesGoldenFile(
            __DIR__ . '/golden/round-results-' . $round . '-' . ($viewer === null ? 'guest' : 'entrant') . '.html',
            self::stable((string) $browser->getResponse()->getContent()),
            'The round page (' . $round . ')',
        );
    }

    /**
     * What belongs to the build or the request, not to the page: asset hashes, CSRF tokens, nonces, the moment of rendering.
     */
    private static function stable(string $html): string
    {
        $html = (string) preg_replace('~(/build/[^"\'\s]+?)\.[0-9a-f]{8,20}\.(js|css|svg|png|webp|woff2?)~', '$1.$2', $html);
        $html = (string) preg_replace('~(integrity=")[^"]*(")~', '$1$2', $html);
        $html = (string) preg_replace('~(nonce=")[^"]*(")~', '$1$2', $html);
        $html = (string) preg_replace('~(<meta name="msp-rendered-at" content=")\d+(")~', '$1$2', $html);

        return (string) preg_replace('~((?:_token|csrf[_-]?token|data-csrf[a-z-]*)["\']?\s*(?:value|content|=|:)\s*=?\s*["\'])[^"\']+~i', '$1', $html);
    }
}
