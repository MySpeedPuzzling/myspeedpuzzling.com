<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\GoldenFile;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Baseline pin of the competition toolkit (R-review-and-plan.md §2.9, package F0): the whole answer of
 * official_results_round_state for the Results Cup's untouched rounds, byte for byte, as the event's organiser reads
 * it (the results desk, seating and live entry bootstrap). Toolkit keys (`puzzleFormat`, `puzzles`, `proposals`,
 * `viewer`, `lineupId`) appear only on rounds and competitions that use them (R §1.8): this must stay green without
 * them.
 */
final class RoundResultsStateGoldenTest extends WebTestCase
{
    use GoldenFile;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untouchedRounds(): iterable
    {
        yield 'solo round, results published' => [OfficialResultsFixture::ROUND_GROUP_A, 'group-a'];
        yield 'solo round' => [OfficialResultsFixture::ROUND_GROUP_B, 'group-b'];
        yield 'pair round' => [OfficialResultsFixture::ROUND_PAIRS, 'pairs'];
    }

    #[DataProvider('untouchedRounds')]
    public function testTheStateOfAnUntouchedRoundIsUnchanged(string $roundId, string $name): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/official-results/rounds/' . $roundId);

        self::assertResponseIsSuccessful();
        self::assertMatchesGoldenFile(
            __DIR__ . '/golden/round-state-' . $name . '.json',
            self::pretty((string) $browser->getResponse()->getContent()),
            'The round state of ' . $name,
        );
    }

    /**
     * Pretty-printed, so a diff names the key - the bytes the server sends are compared through the decoded value. The
     * Mercure subscription token is fresh per request (its iat and jti): it is replaced, the rest of the subscription
     * stays pinned. Ids the fixtures generate at random are masked too.
     */
    private static function pretty(string $json): string
    {
        $state = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($state) && is_array($state['mercure'] ?? null));
        self::assertIsString($state['mercure']['token']);
        self::assertNotSame('', $state['mercure']['token']);
        $state['mercure']['token'] = '<fresh token>';
        // OfficialResultsFixture mints the pair/team members' entry rows with Uuid::uuid7(), so their ids change with
        // every rebuilt test database - masked, the rest of the entry stays pinned
        array_walk_recursive($state, static function (mixed &$value, int|string $key): void {
            if ($key === 'participantRoundId' && is_string($value)) {
                $value = '<fixture uuid7>';
            }
        });

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
    }
}
