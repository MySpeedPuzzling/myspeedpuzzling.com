<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\Collection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ChangeDefaultCollection;
use SpeedPuzzling\Web\Message\CreateCollection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The collection every collection picker preselects (docs/features/collections/default-collection.md).
 * Precedence: ?collection= preset > a submitted value (even an empty one) > the member's default > the system collection.
 */
final class DefaultCollectionTest extends WebTestCase
{
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal-frame', 'HTTP_ORIGIN' => 'http://localhost'];

    public function testAMemberWithoutADefaultStartsWithTheSystemCollection(): void
    {
        $browser = $this->member();

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection');
        self::assertResponseIsSuccessful();
        self::assertSame(Collection::SYSTEM_ID, $this->addFormCollection($crawler));

        // The chips: the system collection first, marked default and pressed
        $chips = $crawler->filter('[data-testid="collection-quick-pick"] button[data-value]');
        self::assertCount(3, $chips);
        self::assertSame(Collection::SYSTEM_ID, $chips->first()->attr('data-value'));
        self::assertSame('true', $chips->first()->attr('aria-pressed'));
        self::assertStringContainsString('Default', $chips->first()->text());
    }

    public function testTheMembersDefaultIsPreselectedAndAPresetStillWins(): void
    {
        $browser = $this->member();
        $this->makeDefault(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC);

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection');
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->addFormCollection($crawler));
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $crawler->filter('[data-testid="collection-quick-pick"] button[aria-pressed="true"]')->attr('data-value'));

        // "Add puzzle here" of a collection page
        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection&collection=' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        self::assertSame(CollectionFixture::COLLECTION_STRIPE_TREFL, $this->addFormCollection($crawler));

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection&collection=' . Collection::SYSTEM_ID);
        self::assertSame(Collection::SYSTEM_ID, $this->addFormCollection($crawler));
    }

    public function testAtMostFiveChipsAndThePresetOneIsAlwaysAmongThem(): void
    {
        $browser = $this->member();
        $bus = self::getContainer()->get(MessageBusInterface::class);

        // 2 own collections + 4 new ones + the system collection = 7 choices
        foreach (['Shelf A', 'Shelf B', 'Shelf C', 'Shelf D'] as $name) {
            $bus->dispatch(new CreateCollection(Uuid::uuid7()->toString(), PlayerFixture::PLAYER_WITH_STRIPE, $name, null, CollectionVisibility::Private));
        }

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection');
        self::assertCount(5, $crawler->filter('[data-testid="collection-quick-pick"] button[data-value]'));
        self::assertStringContainsString('More collections in the search below', $crawler->filter('[data-testid="collection-quick-pick"]')->text());

        // The oldest collection comes last in the list (newest first): preset, it takes the fifth chip
        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection&collection=' . CollectionFixture::COLLECTION_PUBLIC);
        $chips = $crawler->filter('[data-testid="collection-quick-pick"] button[data-value]');
        self::assertCount(5, $chips);
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $chips->last()->attr('data-value'));
        self::assertSame('true', $chips->last()->attr('aria-pressed'));
    }

    public function testASubmittedEmptyCollectionIsNotReplacedByTheDefault(): void
    {
        $browser = $this->member();
        $this->makeDefault(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $crawler = $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => [...$this->collectionSubmission($crawler), 'collection' => ''],
            ...$this->ids($crawler),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertEmpty($this->addFormCollection($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="collection-quick-pick"] button[aria-pressed="true"]'));
    }

    public function testThePreselectedDefaultSavesAndTheFlashNamesIt(): void
    {
        $browser = $this->member();
        $this->makeDefault(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_STRIPE_TREFL);

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection');
        $submission = $this->collectionSubmission($crawler);
        $submission['collection'] = $this->addFormCollection($crawler);

        $browser->request('POST', '/en/puzzle-add', ['puzzle_add_form' => $submission, ...$this->ids($crawler)]);
        self::assertResponseRedirects('/en/collection/' . CollectionFixture::COLLECTION_STRIPE_TREFL);

        self::assertSame(1, $this->countRows(
            'SELECT COUNT(*) FROM collection_item WHERE player_id = :player AND collection_id = :collection AND puzzle_id = :puzzle',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'collection' => CollectionFixture::COLLECTION_STRIPE_TREFL, 'puzzle' => PuzzleFixture::PUZZLE_9000],
        ));

        $browser->followRedirect();
        self::assertSelectorTextContains('body', 'Puzzle added to My Trefl Collection.');
    }

    public function testANonMemberAlwaysGetsTheSystemCollection(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        // A stored default of a lapsed membership
        $this->makeDefault(PlayerFixture::PLAYER_REGULAR, CollectionFixture::COLLECTION_PRIVATE);

        $crawler = $browser->request('GET', '/en/puzzle-add?mode=collection');
        self::assertSame(Collection::SYSTEM_ID, $this->addFormCollection($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="collection-quick-pick"]'));

        $crawler = $browser->request('GET', '/en/collections/' . PuzzleFixture::PUZZLE_9000 . '/add', server: self::FRAME);
        self::assertSame(Collection::SYSTEM_ID, $crawler->filter('input[name="collection_puzzle_action_form[collection]"]')->attr('value'));
    }

    public function testTheAddToCollectionModalPreselectsTheDefaultUnlessThePuzzleIsInIt(): void
    {
        $browser = $this->member();

        $crawler = $browser->request('GET', '/en/collections/' . PuzzleFixture::PUZZLE_9000 . '/add', server: self::FRAME);
        self::assertSame(Collection::SYSTEM_ID, $this->modalCollection($crawler));

        $this->makeDefault(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_PUBLIC);

        $crawler = $browser->request('GET', '/en/collections/' . PuzzleFixture::PUZZLE_9000 . '/add', server: self::FRAME);
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->modalCollection($crawler));
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $crawler->filter('[data-testid="collection-quick-pick"] button[aria-pressed="true"]')->attr('data-value'));

        // PUZZLE_1000_01 is in the default collection already (not in the system one): the system collection then
        $crawler = $browser->request('GET', '/en/collections/' . PuzzleFixture::PUZZLE_1000_01 . '/add', server: self::FRAME);
        self::assertSame(Collection::SYSTEM_ID, $this->modalCollection($crawler));
    }

    public function testTheBulkAddPreselectsTheDefault(): void
    {
        $browser = $this->member();
        $this->makeDefault(PlayerFixture::PLAYER_WITH_STRIPE, CollectionFixture::COLLECTION_STRIPE_TREFL);

        $crawler = $browser->request('POST', '/en/my-lists/wishlist/selected/add-to-collection', [
            '_token' => 'csrf-token',
            'puzzleIds' => [PuzzleFixture::PUZZLE_9000],
        ], server: self::FRAME);

        self::assertResponseIsSuccessful();
        self::assertSame(CollectionFixture::COLLECTION_STRIPE_TREFL, $crawler->filter('input[name="collection_puzzle_action_form[collection]"]')->attr('value'));
    }

    public function testTheCollectionFormsSetAndClearTheDefault(): void
    {
        $browser = $this->member();

        // Edit: tick
        $crawler = $browser->request('GET', '/en/edit-collection/' . CollectionFixture::COLLECTION_PUBLIC);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="collection_form"]')->form();
        self::isDefaultBox($form)->tick();
        $browser->submit($form);
        self::assertResponseRedirects();
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        // The overview marks it, the collection page too
        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="default-collection-badge"]'));
        $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        self::assertSelectorExists('[data-testid="default-collection-badge"]');

        // Editing another collection leaves the default alone
        $crawler = $browser->request('GET', '/en/edit-collection/' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        $form = $crawler->filter('form[name="collection_form"]')->form();
        self::assertFalse(self::isDefaultBox($form)->hasValue());
        $browser->submit($form);
        self::assertSame(CollectionFixture::COLLECTION_PUBLIC, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        // Edit: untick = back to the system collection
        $crawler = $browser->request('GET', '/en/edit-collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $form = $crawler->filter('form[name="collection_form"]')->form();
        self::assertTrue(self::isDefaultBox($form)->hasValue());
        self::isDefaultBox($form)->untick();
        $browser->submit($form);
        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        // Create: ticked = the new collection
        $crawler = $browser->request('GET', '/en/create-collection');
        $form = $crawler->filter('form[name="collection_form"]')->form();
        $form['collection_form[name]'] = 'Jigsaw shelf';
        self::isDefaultBox($form)->tick();
        $browser->submit($form);
        self::assertResponseRedirects();

        $newId = $this->database()->fetchOne('SELECT id FROM collection WHERE player_id = :player AND name = :name', ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'name' => 'Jigsaw shelf']);
        self::assertIsString($newId);
        self::assertSame($newId, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testMakeDefaultAndBackToTheSystemCollection(): void
    {
        $browser = $this->member();

        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        $browser->submit($crawler->filter('[data-testid="make-default-collection"]')->form());
        self::assertResponseRedirects('/en/collection/' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        self::assertSame(CollectionFixture::COLLECTION_STRIPE_TREFL, $this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        // The system collection's page offers the way back
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('[data-testid="make-default-collection"]')->form();
        $browser->submit($form);
        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));

        // Back to the system collection: its page has nothing to offer any more
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertCount(0, $crawler->filter('[data-testid="make-default-collection"]'));

        // Somebody else's collection: not found, nothing changes
        $browser->request('POST', '/en/default-collection/' . CollectionFixture::COLLECTION_PRIVATE, ['_token' => $form->getValues()['_token']]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->defaultOf(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    private function member(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        return $browser;
    }

    private function makeDefault(string $playerId, string $collectionId): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ChangeDefaultCollection($playerId, $collectionId));
    }

    private function addFormCollection(Crawler $crawler): null|string
    {
        return $crawler->filter('input[name="puzzle_add_form[collection]"]')->attr('value');
    }

    private function modalCollection(Crawler $crawler): null|string
    {
        return $crawler->filter('input[name="collection_puzzle_action_form[collection]"]')->attr('value');
    }

    /**
     * @return array<string, string>
     */
    private function collectionSubmission(Crawler $crawler): array
    {
        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        return [
            '_token' => $csrfToken,
            'mode' => 'collection',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'puzzle' => PuzzleFixture::PUZZLE_9000,
            'finishedAt' => '12.07.2026',
        ];
    }

    /**
     * @return array{time_id: null|string, new_puzzle_id: null|string}
     */
    private function ids(Crawler $crawler): array
    {
        return ['time_id' => $crawler->filter('input[name="time_id"]')->attr('value'), 'new_puzzle_id' => $crawler->filter('input[name="new_puzzle_id"]')->attr('value')];
    }

    private function defaultOf(string $playerId): null|string
    {
        $value = $this->database()->fetchOne('SELECT default_collection_id FROM player WHERE id = :id', ['id' => $playerId]);

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function countRows(string $sql, array $parameters): int
    {
        $count = $this->database()->fetchOne($sql, $parameters);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private static function isDefaultBox(Form $form): ChoiceFormField
    {
        $field = $form['collection_form[isDefault]'];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
