<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\GoldenFile;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Baseline pin of the competition toolkit (R-review-and-plan.md §2.9, package F0): the printable name tags page of an
 * untouched competition, byte for byte - the QR paths in it are printed on badges already (`/{_locale}/live/{cid}/p/
 * {participantId}`, R §1.3). The toolkit's tag kinds and layouts (B3) are opt-in: without `kind` / `layout` the page
 * must stay exactly this.
 */
final class CompetitionNameTagsGoldenTest extends WebTestCase
{
    use GoldenFile;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pages(): iterable
    {
        yield 'every participant, by name' => ['', 'default'];
        yield 'one round, by table' => ['?round=' . OfficialResultsFixture::ROUND_GROUP_A . '&sort=table', 'group-a-by-table'];
    }

    #[DataProvider('pages')]
    public function testTheNameTagsOfAnUntouchedCompetitionAreUnchanged(string $query, string $name): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/name-tags/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . $query);

        self::assertResponseIsSuccessful();
        self::assertMatchesGoldenFile(
            __DIR__ . '/golden/name-tags-' . $name . '.html',
            // A flag's build hash belongs to the asset build, not to the page
            (string) preg_replace('~(/build/images/[a-z_-]+)\.[0-9a-f]{8}\.svg~', '$1.svg', (string) $browser->getResponse()->getContent()),
            'The name tags page (' . $name . ')',
        );
    }
}
