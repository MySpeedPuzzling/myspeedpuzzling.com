<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Api\V1;

use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/me/library and GET /api/v1/players/{playerId}/library - the
 * website's puzzle-library page as one summary: the collections with their
 * item counts and the count + visibility of every section (unsolved,
 * wishlist, lend/borrow, sell/swap, solved), under the page's rules: the
 * owner sees everything, a stranger the public sections (a private one
 * reports count 0 and "private"), a private profile is zeroed throughout.
 *
 * Fixtures (.claude/fixtures.md, every list visibility private): PLAYER_WITH_STRIPE
 * (member) - public system collection with 5 puzzles, COLLECTION_STRIPE_TREFL
 * (public, 3) and COLLECTION_PUBLIC (public, 8); 7 unsolved, 3 wished, 4 lent
 * + 2 borrowed, 7 on sale/swap, 10 solved. PLAYER_REGULAR (non-member) - private
 * system collection (3), COLLECTION_FAVORITES (private, 2), COLLECTION_PRIVATE
 * (private, 4); 2 unsolved, 5 wished, 4 lent + 1 borrowed, nothing on sale,
 * 11 solved. PLAYER_PRIVATE - private profile, 1 wished.
 *
 * @phpstan-type Section array{count: int, visibility: string}
 * @phpstan-type Library array{
 *     player_id: string,
 *     collections: list<array{collection_id: string, name: string, description: null|string, visibility: string, item_count: int}>,
 *     unsolved: Section,
 *     wishlist: Section,
 *     lend_borrow: array{lent_count: int, borrowed_count: int, visibility: string},
 *     sell_swap: array{count: int},
 *     solved: Section,
 * }
 */
final class LibraryEndpointTest extends WebTestCase
{
    use PuzzleLibraryEndpointTestHelpers;
    use QueryCountAssertions;

    public function testAuthentication(): void
    {
        $browser = self::createClient();

        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['profile:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath('not-a-uuid'));
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOwnLibraryIsCompleteWhateverTheVisibilitySettings(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $raw = $this->decodeJson($browser);
        $this->assertEqualsCanonicalizing(['player_id', 'collections', 'unsolved', 'wishlist', 'lend_borrow', 'sell_swap', 'solved'], array_keys($raw));

        $library = $this->library($browser);
        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $library['player_id']);

        // the system collection first, then the custom ones newest first, each with its count
        $this->assertSame(
            [
                ['collection_id' => 'default', 'name' => 'Default Collection', 'description' => null, 'visibility' => 'public', 'item_count' => 5],
                ['collection_id' => CollectionFixture::COLLECTION_STRIPE_TREFL, 'name' => 'My Trefl Collection', 'description' => 'All my Trefl puzzles', 'visibility' => 'public', 'item_count' => 3],
                ['collection_id' => CollectionFixture::COLLECTION_PUBLIC, 'name' => 'My Ravensburger Collection', 'description' => 'All my favorite Ravensburger puzzles', 'visibility' => 'public', 'item_count' => 8],
            ],
            $library['collections'],
        );
        $this->assertSame(['count' => 7, 'visibility' => 'private'], $library['unsolved']);
        $this->assertSame(['count' => 3, 'visibility' => 'private'], $library['wishlist']);
        $this->assertSame(['lent_count' => 4, 'borrowed_count' => 2, 'visibility' => 'private'], $library['lend_borrow']);
        $this->assertSame(['count' => 7], $library['sell_swap']);
        $this->assertSame(['count' => 10, 'visibility' => 'private'], $library['solved']);

        // a non-member with everything private sees their own complete library too
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $library = $this->library($browser);
        $this->assertSame(PlayerFixture::PLAYER_REGULAR, $library['player_id']);
        $this->assertSame(
            [
                ['collection_id' => 'default', 'name' => 'Default Collection', 'description' => null, 'visibility' => 'private', 'item_count' => 3],
                ['collection_id' => CollectionFixture::COLLECTION_FAVORITES, 'name' => 'Completed Favorites', 'description' => null, 'visibility' => 'private', 'item_count' => 2],
                ['collection_id' => CollectionFixture::COLLECTION_PRIVATE, 'name' => 'Wishlist', 'description' => 'Puzzles I want to buy', 'visibility' => 'private', 'item_count' => 4],
            ],
            $library['collections'],
        );
        $this->assertSame(['count' => 2, 'visibility' => 'private'], $library['unsolved']);
        $this->assertSame(['count' => 5, 'visibility' => 'private'], $library['wishlist']);
        $this->assertSame(['lent_count' => 4, 'borrowed_count' => 1, 'visibility' => 'private'], $library['lend_borrow']);
        $this->assertSame(['count' => 0], $library['sell_swap']);
        $this->assertSame(['count' => 11, 'visibility' => 'private'], $library['solved']);

        // the visibility the owner set is reported as is
        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_REGULAR, CollectionVisibility::Public);
        $browser->request('GET', $this->myPath());
        $this->assertSame(['count' => 5, 'visibility' => 'public'], $this->library($browser)['wishlist']);
    }

    /**
     * Another player's library as the website shows it to a visitor: public
     * sections with their counts, private ones as count 0 + "private"; public
     * collections only.
     */
    public function testAnotherPlayersLibraryShowsThePublicSectionsOnly(): void
    {
        $browser = self::createClient();

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $library = $this->library($browser);
        $this->assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $library['player_id']);
        // the system collection is public, both custom collections are public
        $this->assertSame(['default', CollectionFixture::COLLECTION_STRIPE_TREFL, CollectionFixture::COLLECTION_PUBLIC], array_column($library['collections'], 'collection_id'));
        $this->assertSame([5, 3, 8], array_column($library['collections'], 'item_count'));
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['unsolved']);
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['wishlist']);
        $this->assertSame(['lent_count' => 0, 'borrowed_count' => 0, 'visibility' => 'private'], $library['lend_borrow']);
        // always public on the website
        $this->assertSame(['count' => 7], $library['sell_swap']);
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['solved']);

        // sections the owner opens up are counted
        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->setUnsolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->setLendBorrowListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->setSolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $library = $this->library($browser);
        $this->assertSame(['count' => 7, 'visibility' => 'public'], $library['unsolved']);
        $this->assertSame(['count' => 3, 'visibility' => 'public'], $library['wishlist']);
        $this->assertSame(['lent_count' => 4, 'borrowed_count' => 2, 'visibility' => 'public'], $library['lend_borrow']);
        $this->assertSame(['count' => 10, 'visibility' => 'public'], $library['solved']);

        // a machine token is a stranger too
        $this->authenticateClientCredentials($browser, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertSame(['count' => 3, 'visibility' => 'public'], $this->library($browser)['wishlist']);

        // PLAYER_REGULAR keeps everything private: no collections at all (system and custom ones private)
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_REGULAR));
        $library = $this->library($browser);
        $this->assertSame([], $library['collections']);
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['unsolved']);
        $this->assertSame(['count' => 0], $library['sell_swap']);

        // ... but a public system collection shows up with its count
        $this->setPuzzleCollectionVisibility($browser, PlayerFixture::PLAYER_REGULAR, CollectionVisibility::Public);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_REGULAR));
        $this->assertSame(
            [['collection_id' => 'default', 'name' => 'Default Collection', 'description' => null, 'visibility' => 'public', 'item_count' => 3]],
            $this->library($browser)['collections'],
        );

        // the player behind the token gets their complete library through /players
        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_REGULAR));
        $library = $this->library($browser);
        $this->assertCount(3, $library['collections']);
        $this->assertSame(['count' => 5, 'visibility' => 'private'], $library['wishlist']);
        $this->assertSame(['lent_count' => 4, 'borrowed_count' => 1, 'visibility' => 'private'], $library['lend_borrow']);
    }

    /**
     * A private profile is zeroed throughout for everybody but the owner, and
     * no count query runs; the owner gets the full summary.
     */
    public function testPrivateProfileIsZeroedWithoutCountQueries(): void
    {
        $browser = self::createClient();
        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_PRIVATE, CollectionVisibility::Public);

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_WITH_STRIPE, ['collections:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertResponseIsSuccessful();
        $library = $this->library($browser);
        $this->assertSame(PlayerFixture::PLAYER_PRIVATE, $library['player_id']);
        $this->assertSame([], $library['collections']);
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['unsolved']);
        // public on the website's settings page, private behind the private profile
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['wishlist']);
        $this->assertSame(['lent_count' => 0, 'borrowed_count' => 0, 'visibility' => 'private'], $library['lend_borrow']);
        $this->assertSame(['count' => 0], $library['sell_swap']);
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $library['solved']);
        // authentication (3) + the listed profile + the token owner's profile
        $this->assertQueryCountAtMost($browser, 5, 'private profile short-circuit');

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $this->assertSame(['count' => 0, 'visibility' => 'private'], $this->library($browser)['wishlist']);
        $this->assertQueryCountAtMost($browser, 2, 'private profile short-circuit, machine token');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_PRIVATE, ['collections:read']);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_PRIVATE));
        $library = $this->library($browser);
        $this->assertSame(['count' => 1, 'visibility' => 'public'], $library['wishlist']);
        $this->assertSame(['count' => 1, 'visibility' => 'private'], $library['unsolved']);
        $this->assertSame('default', $library['collections'][0]['collection_id'] ?? null);
    }

    /**
     * Measured (2026-08-19): authentication 1 (PAT) / 3 (OAuth2) / 1-2
     * (client_credentials), the owner's profile 1, then one count query per
     * visible section: custom collections 1, system collection 1, unsolved 2
     * (collections + borrowed), wishlist 1, lent 1, borrowed 1, sell/swap 1,
     * solved 1 - 9 for a complete library; /players adds the listed profile 1
     * (the token owner's profile is the visibility check, not for a machine
     * token). A stranger pays only for the public sections.
     */
    public function testQueryBudgets(): void
    {
        $browser = self::createClient();

        $this->authenticatePat($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 11, 'own library, PAT');

        $this->authenticateOAuth2($browser, PlayerFixture::PLAYER_REGULAR, ['collections:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->myPath());
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 13, 'own library, authorization-code token');

        // a stranger: the public system collection, the custom collections and the sell/swap count only
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 8, 'another player, private sections skipped');

        $this->setWishListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->setUnsolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->setLendBorrowListVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);
        $this->setSolvedPuzzlesVisibility($browser, PlayerFixture::PLAYER_WITH_STRIPE, CollectionVisibility::Public);

        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 14, 'another player, everything public, authorization-code token');

        $this->authenticateClientCredentials($browser, ['collections:read']);
        $this->startCountingQueries($browser);
        $browser->request('GET', $this->playerPath(PlayerFixture::PLAYER_WITH_STRIPE));
        $this->assertResponseIsSuccessful();
        $this->assertQueryCountAtMost($browser, 11, 'another player, everything public, machine token');
    }

    public function testOpenApiDocumentsBothPaths(): void
    {
        $browser = self::createClient();
        $this->authenticatePat($browser, PlayerFixture::PLAYER_REGULAR);

        $this->assertOpenApiDocumentsPaths($browser, ['/api/v1/me/library', '/api/v1/players/{playerId}/library']);
    }

    private function myPath(): string
    {
        return '/api/v1/me/library';
    }

    private function playerPath(string $playerId): string
    {
        return '/api/v1/players/' . $playerId . '/library';
    }

    /**
     * @return Library
     */
    private function library(KernelBrowser $browser): array
    {
        $this->assertResponseIsSuccessful();

        /** @var Library $library */
        $library = $this->decodeJson($browser);

        return $library;
    }
}
