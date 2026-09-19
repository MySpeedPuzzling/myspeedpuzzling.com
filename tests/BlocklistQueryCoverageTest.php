<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every read-side query that touches the `player` table either filters out the players the viewer
 * has hidden (HiddenPlayers) or says here why it does not. A new query fails this test until its
 * author has made that decision - see docs/features/player-blocklist.md.
 */
final class BlocklistQueryCoverageTest extends TestCase
{
    private const string OWN_DATA = 'Only ever returns the viewer\'s / subject\'s own data; the subject is gated by GetPlayerProfile::byId().';
    private const string ADMIN = 'Admin or moderation tooling - sees everyone by design.';
    private const string ORGANISER = 'Organiser tooling behind COMPETITION_EDIT - blocking must not make a participant unassignable.';
    private const string BILATERAL = 'Bilateral history (lending, sales, an open conversation) stays readable after a block.';
    private const string BACKGROUND = 'Background / write-side lookup with no viewer (cron, sync, notification fan-out).';
    private const string AGGREGATE = 'Aggregate without player identity - out of scope.';

    private const array NOT_FILTERED = [
        'FindPlayerByNameAndCountry' => self::BACKGROUND,
        'GetAdminReferralDetail' => self::ADMIN,
        'GetAdminReferralsOverview' => self::ADMIN,
        'GetAllVouchers' => self::ADMIN,
        'GetBorrowedPuzzles' => self::BILATERAL,
        'GetCollectionDisplayMode' => self::OWN_DATA,
        'GetCompetitionEvents' => 'The organiser\'s name is part of a public event, not a player listing.',
        'GetCompetitionParticipantsForManagement' => self::ORGANISER,
        'GetCompetitionSeries' => 'The organiser\'s name is part of a public event, not a player listing.',
        'GetConversationLog' => self::ADMIN,
        'GetConversationPartnersForListing' => self::BILATERAL,
        'GetConversations' => 'Filters on user_block itself (predates HiddenPlayers).',
        'GetExportableSolvingTimes' => self::OWN_DATA,
        'GetFeatureRequestVoters' => self::BACKGROUND,
        'GetLendBorrowHistory' => self::BILATERAL,
        'GetLentPuzzleHistory' => self::BILATERAL,
        'GetLentPuzzleIds' => self::BILATERAL,
        'GetLentPuzzles' => self::BILATERAL,
        'GetMessages' => self::BILATERAL,
        'GetModerationActions' => self::ADMIN,
        'GetModerators' => 'Public list of a site role, like the team page.',
        'GetNewsletterRecipients' => self::BACKGROUND,
        'GetOAuth2ClientRequests' => self::ADMIN,
        'GetPendingPuzzleProposals' => self::ADMIN,
        'GetPlayerIdByEmail' => self::BACKGROUND,
        'GetPlayerIdsForSitemap' => self::BACKGROUND,
        'GetPlayerProfile' => 'Is the gate: byId() answers PlayerNotFound for a hidden player.',
        'GetPlayersForWjpfSync' => self::BACKGROUND,
        'GetPlayerStatistics' => self::OWN_DATA,
        'GetPlayersWithUnreadMessages' => 'Filters on user_block itself (predates HiddenPlayers).',
        'GetPuzzleChangeRequests' => self::ADMIN,
        'GetPuzzleMergeRequests' => self::ADMIN,
        'GetPuzzleMergeReviewQueue' => self::ADMIN,
        'GetPuzzleTracking' => self::OWN_DATA,
        'GetReports' => self::ADMIN,
        'GetRoundTeams' => self::ORGANISER,
        'GetSoldSwappedHistory' => self::BILATERAL,
        'GetStatistics' => self::AGGREGATE,
        'GetSubscribedPlayers' => self::BACKGROUND,
        'GetTableLayoutForRound' => self::ORGANISER,
        'GetUserBlocks' => 'The blocklist itself.',
        'GetUserPuzzleStatuses' => self::BILATERAL,
        'GetUserSolvedPuzzles' => self::OWN_DATA,
    ];

    public function testEveryQueryTouchingPlayersHasDecidedAboutTheBlocklist(): void
    {
        $undecided = [];
        $staleAllowlist = self::NOT_FILTERED;

        foreach (glob(__DIR__ . '/../src/Query/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $class = basename($file, '.php');

            if (preg_match('/\b(JOIN|FROM)\s+player\b/i', $source) !== 1) {
                continue;
            }

            unset($staleAllowlist[$class]);

            $filters = preg_match('/HiddenPlayers \$hiddenPlayers/', $source) === 1;

            if ($filters === false && array_key_exists($class, self::NOT_FILTERED) === false) {
                $undecided[] = $class;
            }
        }

        self::assertSame([], $undecided, 'These queries read players but neither use HiddenPlayers nor are allow-listed with a reason.');
        self::assertSame([], array_keys($staleAllowlist), 'Allow-listed queries that no longer exist or no longer touch the player table.');
    }
}
