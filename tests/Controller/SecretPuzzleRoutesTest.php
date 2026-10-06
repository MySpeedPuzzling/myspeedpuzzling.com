<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\AddPuzzleToWishList;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Every page and every write of one puzzle: a secret competition puzzle does not exist for a guest, another player
 * or a community moderator - only for the event's maintainers and admins.
 */
final class SecretPuzzleRoutesTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string SECRET_NAME = 'Heart of Wisconsin Secret';
    private const string SECRET_EAN = '4005556175512';

    /**
     * @return iterable<string, array{string, bool}> path (with {id}), whether its organisers reach it
     */
    public static function guardedRoutes(): iterable
    {
        yield 'puzzle page' => ['/en/puzzle/{id}', true];
        yield 'marketplace puzzle page' => ['/en/marketplace/puzzle/{id}', true];
        yield 'suggest a change' => ['/en/puzzle/{id}/suggest-change', true];
        yield 'pending proposals' => ['/en/puzzle/{id}/pending-proposals', true];
        yield 'QR code' => ['/en/puzzle/{id}/qr-code', true];
        yield 'suggest a name' => ['/en/puzzle/{id}/suggest-name', false];
        yield 'add to a collection' => ['/en/collections/{id}/add', true];
        yield 'add to the wishlist' => ['/en/wishlist/{id}/add', true];
        yield 'sell or swap' => ['/en/sell-swap/{id}/add', true];
        yield 'lend' => ['/en/lend/{id}', true];
        yield 'borrow' => ['/en/borrow/{id}', true];
        yield 'stopwatch' => ['/en/puzzle-stopwatch/{id}', true];
        yield 'start a stopwatch' => ['/en/start-stopwatch/{id}', true];
        yield 'add a time' => ['/en/puzzle-add/{id}', true];
        yield 'move in the collections' => ['/en/collections/{id}/move', true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guardedRoutes')]
    public function testSecretPuzzleExistsOnlyForItsOrganisers(string $path, bool $organisersReachIt): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        $url = str_replace('{id}', $puzzleId, $path);

        // A guest: nothing (404, or the sign-in page - never the puzzle)
        $browser->request('GET', $url);
        self::assertNotSame(200, $browser->getResponse()->getStatusCode(), 'guest');
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());

        foreach ([PlayerFixture::PLAYER_PRIVATE => 'another player', PlayerFixture::PLAYER_WITH_STRIPE => 'a moderator'] as $playerId => $who) {
            TestingLogin::asPlayer($browser, $playerId);
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $who);
        }

        if ($organisersReachIt === false) {
            return;
        }

        foreach ([PlayerFixture::PLAYER_WITH_FAVORITES => 'a maintainer of the event', PlayerFixture::PLAYER_ADMIN => 'an admin'] as $playerId => $who) {
            TestingLogin::asPlayer($browser, $playerId);
            $browser->request('GET', $url);
            self::assertNotSame(404, $browser->getResponse()->getStatusCode(), $who);
            self::assertLessThan(500, $browser->getResponse()->getStatusCode(), $who);
        }
    }

    public function testAdminPagesOfASecretPuzzleAreForAdminsOnly(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();

        foreach (['/admin/puzzles/{id}/edit', '/admin/puzzles/{id}/history'] as $path) {
            $url = str_replace('{id}', $puzzleId, $path);

            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
            $browser->request('GET', $url);
            self::assertResponseStatusCodeSame(404, 'a moderator: ' . $path);

            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful('an admin: ' . $path);
        }
    }

    public function testWritesRefuseASecretPuzzleWhoeverSendsThem(): void
    {
        self::createClient();
        $puzzleId = $this->secretPuzzle();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        try {
            $bus->dispatch(new AddPuzzleToWishList(PlayerFixture::PLAYER_PRIVATE, $puzzleId));
            self::fail('Another player cannot use a secret puzzle (form, API or multiscan)');
        } catch (PuzzleNotFound) {
        }

        // Its adder neither - nothing personal before the reveal (SecretPuzzleWritesTest has every write)
        try {
            $bus->dispatch(new AddPuzzleToWishList(PlayerFixture::PLAYER_REGULAR, $puzzleId));
            self::fail('Nobody records anything on a secret puzzle before its reveal');
        } catch (PuzzleNotRevealedYet) {
        }

        self::assertFalse(self::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT 1 FROM wish_list_item WHERE puzzle_id = :puzzleId',
            ['puzzleId' => $puzzleId],
        ));
    }

    public function testAnOrganiserIsToldWhenTheSecretOpens(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // A modal (Turbo Frame): the message takes the frame's place
        $browser->request('POST', '/en/wishlist/' . $puzzleId . '/add', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseStatusCodeSame(422);
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="modal-frame">', $content);
        self::assertStringContainsString('This puzzle is still secret until', $content);
        self::assertStringContainsString('you can add it after the reveal', $content);

        // A full page: back where it came from, with the message
        $browser->request('POST', '/en/wishlist/' . $puzzleId . '/add', server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseStatusCodeSame(303);
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('This puzzle is still secret until', $crawler->text());
    }

    public function testReportingADuplicateOfASecretPuzzleIsRefusedUnseen(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        // Image only - the name is public, but a report shows and proposes codes
        $browser->request('POST', str_replace('{id}', $puzzleId, '/en/puzzle/{id}/report-duplicate'), ['duplicate_puzzle_ids' => [PuzzleFixture::PUZZLE_500_05]]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMarketplaceFilterIsNoWayToASecretPuzzle(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle();

        // The puzzle filter is a writable prop - anybody can send any id in a Live request
        $render = function (string $playerId) use ($browser, $puzzleId): string {
            TestingLogin::asPlayer($browser, $playerId);
            $component = $this->createLiveComponent('MarketplaceListing', ['puzzleId' => $puzzleId], $browser);
            $component->setRouteLocale('en');

            return $component->render()->toString();
        };

        self::assertStringNotContainsString(self::SECRET_NAME, $render(PlayerFixture::PLAYER_PRIVATE));
        // Its organisers do get the filter
        self::assertStringContainsString(self::SECRET_NAME, $render(PlayerFixture::PLAYER_REGULAR));
    }

    public function testThePuzzlePickerListsACompetitionsSecretsOnlyForItsOrganisers(): void
    {
        $browser = self::createClient();
        $this->secretPuzzle();
        $picker = '/en/puzzle-by-brand-autocomplete/?brand=' . ManufacturerFixture::MANUFACTURER_RAVENSBURGER . '&competition=' . CompetitionApiFixture::COMPETITION_API;

        // Naming the competition opens nothing for somebody who does not organise it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', $picker);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());

        // A maintainer of it gets its secret puzzles
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', $picker);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
    }

    public function testAnotherEventsOrganiserCannotPutAPictureSecretIntoTheirRound(): void
    {
        $browser = self::createClient();
        $puzzleId = $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        // PLAYER_PRIVATE organises WJPC 2024, nothing of the competition keeping the picture secret
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $wjpc = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_WJPC_2024);
        $organiser = $entityManager->find(Player::class, PlayerFixture::PLAYER_PRIVATE);
        self::assertNotNull($wjpc);
        self::assertNotNull($organiser);
        $wjpc->maintainers->add($organiser);
        $entityManager->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', '/en/add-puzzle-to-round/' . CompetitionRoundFixture::ROUND_WJPC_FINAL);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form')->last()->form();
        $prefix = (string) $crawler->filter('input[name$="[puzzle]"]')->attr('name');
        $prefix = substr($prefix, 0, (int) strpos($prefix, '['));

        $browser->submit($form, [
            $prefix . '[brand]' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            $prefix . '[puzzle]' => $puzzleId,
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse(self::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT 1 FROM competition_round_puzzle WHERE puzzle_id = :puzzleId AND round_id = :roundId',
            ['puzzleId' => $puzzleId, 'roundId' => CompetitionRoundFixture::ROUND_WJPC_FINAL],
        ));
    }

    public function testBarcodeSearchDoesNotFindAPicturelessSecret(): void
    {
        $browser = self::createClient();
        $this->secretPuzzle(PuzzleHideMode::ImageOnly);

        $browser->request('GET', '/en/puzzle-by-ean-search/' . self::SECRET_EAN);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $browser->getResponse()->getContent());
    }

    private function secretPuzzle(PuzzleHideMode $hideMode = PuzzleHideMode::Entirely): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // A maintainer of the event who did not add the puzzle, and a community moderator
        $competition = $entityManager->find(Competition::class, CompetitionApiFixture::COMPETITION_API);
        $maintainer = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_FAVORITES);
        $moderator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($competition);
        self::assertNotNull($maintainer);
        self::assertNotNull($moderator);
        $competition->maintainers->add($maintainer);
        $moderator->moderatorSince = new DateTimeImmutable('-1 year');
        $entityManager->flush();

        $roundPuzzleId = Uuid::uuid7();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: $roundPuzzleId,
            roundId: CompetitionApiFixture::ROUND_FUTURE,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: self::SECRET_NAME,
            piecesCount: 1000,
            puzzlePhoto: null,
            eans: EanList::fromStored(self::SECRET_EAN),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: $hideMode,
        ));
        $entityManager->clear();

        $roundPuzzle = $entityManager->find(CompetitionRoundPuzzle::class, $roundPuzzleId);
        self::assertNotNull($roundPuzzle);

        return $roundPuzzle->puzzle->id->toString();
    }
}
