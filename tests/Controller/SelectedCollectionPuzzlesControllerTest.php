<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Selecting several puzzles on a collection page (docs/features/collections/bulk-actions.md): members on their own
 * pages only; the bar posts the ids into the modal frame, the modal's submit answers Turbo Streams.
 */
final class SelectedCollectionPuzzlesControllerTest extends WebTestCase
{
    private const string PUBLIC_PAGE = '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC;
    private const string MOVE = '/en/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/selected/move';
    private const string COPY = '/en/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/selected/copy';
    private const string REMOVE = '/en/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/selected/remove';
    private const string CHECKBOX = '[data-collection-selection-target="checkbox"]';
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ORIGIN' => 'http://localhost'];

    public function testOnlyAMemberOnTheirOwnPageGetsTheCheckboxes(): void
    {
        $browser = self::createClient();

        $browser->request('GET', self::PUBLIC_PAGE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CHECKBOX);

        // A non-member on their own collection
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PRIVATE);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::CHECKBOX);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', self::PUBLIC_PAGE);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(1, $crawler->filter(self::CHECKBOX)->count());
        self::assertSelectorExists('form.collection-selection-bar');

        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(1, $crawler->filter(self::CHECKBOX)->count());
    }

    public function testTheBarOpensThePickerCarryingTheSelection(): void
    {
        $browser = $this->member();

        $crawler = $browser->request('POST', self::MOVE, [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_04],
        ], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('turbo-frame#modal-frame input[name="puzzleIds[]"]'));
        self::assertSelectorTextContains('.modal-title', 'Move 2 puzzles');
    }

    public function testMoveAnswersStreamsThatTakeTheCardsOff(): void
    {
        $browser = $this->member();

        $browser->request('POST', self::MOVE, $this->picked([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02]), server: self::FRAME);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('target="library-collection-' . PuzzleFixture::PUZZLE_500_01 . '"', $content);
        self::assertStringContainsString('target="library-collection-' . PuzzleFixture::PUZZLE_500_02 . '"', $content);
        self::assertStringContainsString('1 puzzle moved to', $content);
        self::assertStringContainsString('1 was already there.', $content);
        self::assertSame(0, $this->countIn(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_01));
        self::assertSame(1, $this->countIn(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_01));
    }

    public function testCopyLeavesTheCardsOnThePage(): void
    {
        $browser = $this->member();

        $browser->request('POST', self::COPY, $this->picked([PuzzleFixture::PUZZLE_500_04]), server: self::FRAME);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('action="remove"', $content);
        self::assertStringContainsString('1 puzzle copied to', $content);
        self::assertSame(1, $this->countIn(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04));
        self::assertSame(1, $this->countIn(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_04));
    }

    public function testRemoveAsksFirstThenRemoves(): void
    {
        $browser = $this->member();
        $selection = ['_token' => 'csrf-token', 'puzzleIds' => [PuzzleFixture::PUZZLE_500_04]];

        $browser->request('POST', self::REMOVE, $selection, server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame input[name="confirm"]');
        self::assertSame(1, $this->countIn(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04));

        $browser->request('POST', self::REMOVE, $selection + ['confirm' => '1'], server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 puzzle removed from', (string) $browser->getResponse()->getContent());
        self::assertSame(0, $this->countIn(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04));
    }

    public function testWithoutTurboItGoesBackToTheCollectionWithAFlash(): void
    {
        $browser = $this->member();

        $browser->request('POST', self::REMOVE, [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_04],
            'confirm' => '1',
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects(self::PUBLIC_PAGE);
    }

    public function testNonMembersAndForeignCollectionsAreRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('POST', '/en/collections/' . CollectionFixture::COLLECTION_PRIVATE . '/selected/remove', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_1500_01],
            'confirm' => '1',
        ], server: self::FRAME);
        self::assertResponseStatusCodeSame(403);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('POST', '/en/collections/' . CollectionFixture::COLLECTION_PRIVATE . '/selected/remove', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_1500_01],
            'confirm' => '1',
        ], server: self::FRAME);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAForgedRequestChangesNothing(): void
    {
        $browser = $this->member();
        $browser->request('POST', self::REMOVE, [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_500_04],
            'confirm' => '1',
        ], server: ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->countIn(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04));
    }

    private function member(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        return $browser;
    }

    /**
     * @param list<string> $puzzleIds
     * @return array<string, mixed>
     */
    private function picked(array $puzzleIds): array
    {
        return [
            '_token' => 'csrf-token',
            'puzzleIds' => $puzzleIds,
            'collection_puzzle_action_form' => [
                'collection' => CollectionFixture::COLLECTION_STRIPE_TREFL,
                'collectionVisibility' => 'private',
            ],
        ];
    }

    private function countIn(string $collectionId, string $puzzleId): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM collection_item WHERE player_id = :player AND collection_id = :collection AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'collection' => $collectionId, 'puzzle' => $puzzleId],
        );
        assert(is_int($count));

        return $count;
    }
}
