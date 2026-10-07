<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Whether a private player is hidden from the current viewer is decided in ONE place,
 * PrivateProfileAccess (docs/features/private-profile-allow-list.md). A query that reads the raw
 * `is_private` column decides for itself instead - always hiding, which is safe, but blind to the
 * allow list. So every such query either is listed here with its reason, or fails this test until
 * its author has made that decision.
 */
final class PrivateProfileQueryCoverageTest extends TestCase
{
    private const string GLOBAL_RANKING = 'Global ranking / leaderboard: private players are left out for everybody - nobody is ranked differently for different viewers.';
    private const string BACKGROUND = 'Background job with no viewer (cron, sync, sitemap).';

    /** Files allowed to read the raw column, besides going through PrivateProfileAccess */
    private const array RAW_COLUMN = [
        'Services/Community/CommunityStatsCalculator.php' => 'Counts public newcomers per scope (community_scope_stats.new_faces14d) - an aggregate without identity, the raw setting decides who is counted.',
        'Query/FindSimilarSpeedPuzzler.php' => 'A random suggestion of somebody at your speed offers public profiles only - the same pool for every viewer, like a global ranking.',
        'Query/GetAffiliateSupporters.php' => 'Public supporters list of somebody else\'s profile - stays public-only.',
        'Query/GetFastestGroups.php' => 'fastest_teams keeps a group only if a member is public - the same rows and positions for every viewer; names on them go through the service.',
        'Query/GetFastestPairs.php' => 'fastest_teams keeps a pair only if a member is public - the same rows and positions for every viewer; names on them go through the service.',
        'Query/GetFastestPlayers.php' => self::GLOBAL_RANKING,
        'Query/GetFavoritePlayers.php' => self::GLOBAL_RANKING,
        'Query/GetPlayerConnections.php' => 'Also reports the raw setting (is_private_profile) for the API\'s is_private field; masking uses the service.',
        'Query/GetPlayerIdsForSitemap.php' => self::BACKGROUND,
        'Query/GetPlayerProfile.php' => 'byUserId() is the signed-in player\'s own profile (its comparison line-up avatars are masked in PlayerProfile against the revealed ids of that same row); byId() also reports the raw setting as is_private_profile. Masking uses the service.',
        'Query/GetPlayerRatingRanking.php' => self::GLOBAL_RANKING,
        'Query/GetPlayersDirectory.php' => 'Players page list: public profiles only, for everybody - nobody is ranked differently for different viewers.',
        'Query/GetPlayersForWjpfSync.php' => self::BACKGROUND,
        'Query/GetPlayersOnARoll.php' => 'Players page list: public profiles only, for everybody - nobody is ranked differently for different viewers.',
        'Query/GetPlayersPerCountry.php' => self::GLOBAL_RANKING,
        'Query/GetSpotlightPeople.php' => 'Players page list: public profiles only, for everybody - nobody is ranked differently for different viewers.',
        'Query/GetStopwatchMilestones.php' => self::GLOBAL_RANKING,
        'Query/GetSuggestedPlayers.php' => 'Players page list: public profiles only, for everybody - nobody is ranked differently for different viewers.',
        'Services/ComparisonSubjectVisibility.php' => 'Write side with an explicit owner id: the owner\'s allow-list row is read in the same statement - handlers never see the ambient viewer.',
        'Services/PrivateProfileAccess.php' => 'The one place that decides.',
        'Services/PuzzleIntelligence/MspRatingCalculator.php' => self::BACKGROUND,
        'Services/PuzzleIntelligence/PuzzleIntelligenceRecalculator.php' => self::BACKGROUND,
    ];

    public function testEveryReadOfTheRawColumnIsADecision(): void
    {
        $undecided = [];
        $stale = self::RAW_COLUMN;
        $root = (string) realpath(__DIR__ . '/../src');

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // API resources are DTOs whose OpenAPI descriptions talk about the is_private field in prose
            if (str_contains($file->getPathname(), '/src/Api/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // Any raw read of the column: `alias.is_private`, or a bare `is_private` that is not what
            // results merely carry - a column alias ("AS is_private"), a JSON key ("'is_private',"),
            // an array key / PHPDoc shape ("is_private:", "['is_private']") or a PHP variable.
            if (preg_match('/(\\w\\.is_private\\b|(?<!AS )(?<![\\w$\'"\\[])\\bis_private\\b(?![\'":?\\]]))/', $source) !== 1) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            unset($stale[$relative]);

            if (array_key_exists($relative, self::RAW_COLUMN) === false) {
                $undecided[] = $relative;
            }
        }

        sort($undecided);

        self::assertSame([], $undecided, 'These files read player.is_private directly. Select PrivateProfileAccess::sqlIsPrivate() / sqlIsPublic() instead, or list the file here with the reason.');
        self::assertSame([], array_keys($stale), 'These files no longer read the raw column - remove them from the list.');
    }
}
