<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The one link path (docs/features/competitions-management/participant-linking.md §4, D1): a competition participant is
 * linked to or unlinked from an account only through ParticipantLinks, which records how (`connected_via`) and writes
 * the participant_link_log. So `->connect(` / `->disconnect()` - CompetitionParticipant's link methods - are called in
 * src/ only there (a token scan, like SolvingTimeDeletionCoverageTest), and SQL writes the link columns only in the
 * account deletion's set-based release (§11). Tests and fixtures may call the entity directly (R12).
 */
final class ParticipantLinkCoverageTest extends TestCase
{
    private const string LINK_PATH = 'src/Services/ParticipantLinking/ParticipantLinks.php';

    private const array SQL_LINK_WRITERS = [
        'src/Services/ParticipantLinking/ParticipantLinksOfDeletedAccount.php' => 'account deletion (DeletePlayerHandler) unlinks every row of the account at once and logs `account_deleted` (§11)',
    ];

    public function testOnlyParticipantLinksCallsConnectAndDisconnect(): void
    {
        $found = [];

        foreach (self::sourceFiles() as $path => $code) {
            if ($path !== self::LINK_PATH && self::callsLinkMethod($code)) {
                $found[] = $path;
            }
        }

        self::assertSame([], $found, 'These files link or unlink a competition participant themselves - go through ParticipantLinks::link() / unlink() (participant-linking.md §4)');
    }

    public function testTheLinkPathIsWhereTheCallsAre(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__) . '/' . self::LINK_PATH);

        self::assertTrue(self::callsLinkMethod($code), 'ParticipantLinks no longer calls connect()/disconnect() - update this guard');
    }

    public function testOnlyTheDecidedFilesWriteTheLinkColumnsInSql(): void
    {
        $found = [];

        foreach (self::sourceFiles() as $path => $code) {
            if (preg_match('/UPDATE\s+competition_participant\b[^;]*?\bSET\b[^;]*?\b(player_id|connected_at|connected_via)\s*=/is', $code) === 1) {
                $found[] = $path;
            }
        }

        sort($found);

        self::assertSame(array_keys(self::SQL_LINK_WRITERS), $found, 'A file writes competition_participant\'s link columns in SQL - go through ParticipantLinks, or list the file here with why');
    }

    public function testTheScanCatchesTheCalls(): void
    {
        self::assertTrue(self::callsLinkMethod('<?php $participant->connect($player, $now);'));
        self::assertTrue(self::callsLinkMethod('<?php $this->participants[0]->disconnect ();'));
        self::assertTrue(self::callsLinkMethod('<?php $participant?->disconnect();'));
        self::assertFalse(self::callsLinkMethod('<?php // $participant->connect($player, $now);'));
        self::assertFalse(self::callsLinkMethod('<?php $database->getConnection(); $x->connectedAt; $this->connect($a);'));
    }

    private static function callsLinkMethod(string $code): bool
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;
            $next = $tokens[$index + 2] ?? null;
            $receiver = $tokens[$index - 1] ?? null;

            // $this->connect(…) is a class's own method, not the participant's
            if (is_array($receiver) && $receiver[0] === T_VARIABLE && $receiver[1] === '$this') {
                continue;
            }

            if (is_array($name) && $name[0] === T_STRING && in_array(strtolower($name[1]), ['connect', 'disconnect'], true) && $next === '(') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return iterable<string, string>
     */
    private static function sourceFiles(): iterable
    {
        $root = dirname(__DIR__);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            yield substr($file->getPathname(), strlen($root) + 1) => (string) file_get_contents($file->getPathname());
        }
    }
}
