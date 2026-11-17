<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitorPage;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CompetitionLineupId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The competitor page and its state JSON cost a fixed number of statements (toolkit B-qr-competitor-page.md §4.4,
 * R-review-and-plan.md §4.2 B4) - hundreds of phones refetch the state at a round's start. Pinned exactly; the second
 * request is counted (the first warms the caches).
 *
 * GetCompetitorPage: the competition with its rounds, the tag's entries with their people, the round puzzles, and the
 * published rounds' official entries (OfficialEntrantsView: one statement per category of published rounds - none
 * while nothing shown is published). Signed in adds what every signed-in request loads (account, profile, unread
 * counts) - the JSON adds only the account and the profile (2): the blocklist and allow list come with the profile.
 */
final class CompetitorPageQueryBudgetTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * @return iterable<string, array{string, null|string, int}>
     */
    public static function providePages(): iterable
    {
        $cup = F::COMPETITION_RESULTS_CUP;
        $sharks = CompetitionLineupId::of($cup, [F::PARTICIPANT_ANNA, F::PARTICIPANT_BEN]);

        // Anna: Group A published (solo), Pairs and the Final not
        yield 'person page, guest' => ['/en/competitor/' . $cup . '/p/' . F::PARTICIPANT_ANNA, null, 4];
        yield 'person state, guest' => ['/competitor-state/' . $cup . '/p/' . F::PARTICIPANT_ANNA, null, 4];
        yield 'person page, signed in' => ['/en/competitor/' . $cup . '/p/' . F::PARTICIPANT_ANNA, PlayerFixture::PLAYER_REGULAR, 8];
        yield 'person state, signed in' => ['/competitor-state/' . $cup . '/p/' . F::PARTICIPANT_ANNA, PlayerFixture::PLAYER_REGULAR, 6];
        // Nothing published: no official entries read
        yield 'line-up page, guest' => ['/en/competitor/' . $cup . '/g/' . $sharks, null, 3];
        // Table 1: a published solo round, unpublished ones
        yield 'table page, guest' => ['/en/competitor/' . $cup . '/t/1', null, 4];
        yield 'table state, guest' => ['/competitor-state/' . $cup . '/t/1', null, 4];
        yield 'entry page, guest' => ['/en/competitor/' . $cup . '/e/' . F::ENTRY_A_BEN, null, 4];
    }

    #[DataProvider('providePages')]
    public function testThePageCost(string $url, null|string $playerId, int $statements): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET competitor_pages = true, competitor_pages_live_results = true WHERE id = :id',
            ['id' => F::COMPETITION_RESULTS_CUP],
        );

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        self::assertSame($statements, $this->measure($browser, $url), ($playerId ?? 'guest') . ' ' . $url . "\n" . implode("\n", $this->executedSql($browser)));
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
