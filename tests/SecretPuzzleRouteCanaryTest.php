<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every route that takes a puzzle id is either exercised with a secret competition puzzle in SecretPuzzleRoutesTest
 * (its path appears there, `{puzzleId}` written as `{id}`) or listed below with the reason it needs no guard. A new
 * puzzle route fails here until somebody decides - "move in the collections" printed a secret puzzle's name because
 * nobody had.
 */
final class SecretPuzzleRouteCanaryTest extends KernelTestCase
{
    /**
     * Route name (without the locale suffix) => why a secret puzzle needs no guard there.
     */
    private const array ALLOWED = [
        '_api_/v1/puzzles/{puzzleId}_get' => 'PuzzleDetailResponseProvider answers 404 while hide_until is ahead',
        '_api_/v1/me/puzzles/{puzzleId}/predicted-time_get' => 'numbers only, no name or picture (the 200 is a known existence signal, docs/TODO.md)',
        'admin_puzzle_approval_detail' => 'admins and moderators only; GetPuzzleApprovals leaves secret puzzles out, so the page is a 404',
        'admin_merge_unapproved_puzzle' => 'admins and moderators only; merges of a secret puzzle are refused (PuzzleIsStillSecret)',
        'admin_time_verification_slow_threshold' => 'admins and moderators only, POST; a secret puzzle has no card (GetSuspiciousTimeQueue leaves its times out) and is refused (PuzzleIsStillSecret)',
        'first_try_conflict_resolve' => 'POST on the player\'s own results only - nothing can be recorded before the reveal',
        'legacy_add_time' => '301 to puzzle_add, which is guarded',
        'puzzle_detail_qr' => '301 to puzzle_detail, which is guarded',
        'puzzle_qr_redirect' => 'redirect to puzzle_detail, which is guarded',
        'puzzle_qr_code_image' => 'encodes the /p/{id} link only - reads nothing about the puzzle',
        'collection_remove' => 'removes the viewer\'s own collection item - none can be added before the reveal',
        'remove_puzzle_from_collection' => 'removes the viewer\'s own collection item - none can be added before the reveal',
        'remove_puzzle_from_wish_list' => 'removes the viewer\'s own wishlist item - none can be added before the reveal',
        'sellswap_remove' => 'removes the viewer\'s own listing - none can be added before the reveal',
    ];

    public function testEveryPuzzleRouteIsGuardedOrExplained(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $guardedTest = (string) file_get_contents(__DIR__ . '/Controller/SecretPuzzleRoutesTest.php');

        $unexplained = [];
        $seen = [];

        foreach ($routes->all() as $name => $route) {
            $takesPuzzleId = false;

            foreach ($route->compile()->getPathVariables() as $variable) {
                $takesPuzzleId = $takesPuzzleId || in_array($variable, ['puzzleId', 'puzzle'], true);
            }

            if ($takesPuzzleId === false) {
                continue;
            }

            $baseName = (string) preg_replace('/\.(cs|en|es|ja|fr|de)$/', '', $name);
            $locale = $route->getDefault('_locale');

            // A localized route counts once, by its English path
            if (is_string($locale) && $locale !== 'en' && preg_match('/\.(cs|es|ja|fr|de)$/', $name) === 1) {
                continue;
            }

            $seen[$baseName] = true;
            $path = str_replace(['{puzzleId}', '{puzzle}', '{_locale}'], ['{id}', '{id}', 'en'], $route->getPath());

            if (isset(self::ALLOWED[$baseName]) || str_contains($guardedTest, "'" . $path . "'")) {
                continue;
            }

            $unexplained[] = $baseName . ' ' . $route->getPath();
        }

        self::assertSame([], $unexplained, 'Routes taking a puzzle id without a secret-puzzle test: add them to SecretPuzzleRoutesTest, or to ALLOWED here with the reason.');

        // An allowlist entry for a route that is gone hides nothing - keep the list honest
        self::assertSame([], array_values(array_diff(array_keys(self::ALLOWED), array_keys($seen))), 'Allowlisted routes that no longer exist');
    }
}
