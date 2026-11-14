<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OAuth2ClientFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A pair's result is one row of two players, and each has their own private values on it
 * (docs/features/custom-fields.md "Private by construction"). The canary: A and B solved a puzzle together, A wrote
 * into A's own fields - and nothing B, another player or a guest can open carries A's values, the names of A's
 * fields or their ids. **Add every new surface that shows a result or a player here.**
 *
 * The same for the puzzles of a collection, whose page is often PUBLIC: what the owner wrote into their own fields on
 * them is on no page another player or a guest can open, in no API answer - and not on the collection's page for the
 * owner either. **Add every new surface that shows a collection, its puzzles or a list made from them here.**
 */
final class CustomValuesPrivacyCanaryTest extends WebTestCase
{
    private const string A = PlayerFixture::PLAYER_REGULAR;
    private const string A_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string B = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string C = PlayerFixture::PLAYER_WITH_STRIPE;

    // Words that appear nowhere else in the app
    private const string FIELD_NAME = 'Quokka ledger';
    private const string CHOICE_FIELD_NAME = 'Wombat mood';
    private const string TEXT_VALUE = 'axolotl-whispers-at-dawn';
    private const string CHOICE_VALUE = 'narwhal-grumpy';
    private const string TAG_VALUE = 'capybara-tag';

    // The owner of a PUBLIC collection (and of a public main collection), and what they wrote on its puzzles
    private const string COLLECTOR = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string SHELF_FIELD_NAME = 'Tapir shelf';
    private const string SHELF_VALUE = 'echidna-under-the-stairs';
    private const string ITEM_TAG_VALUE = 'kinkajou-tag';

    /**
     * @return iterable<string, array{null|string, list<string>}> who looks (null = a guest), and at which pages -
     *                                                             `{time}`, `{a}`, `{b}`, `{puzzle}` are filled in
     */
    public static function provideViewers(): iterable
    {
        $shared = [
            '/en/result/{time}',
            'frame:/en/result/{time}',
            '/en/puzzle/{puzzle}',
            '/en/player-profile/{a}',
            '/en/player-profile/{b}',
            '/en/recent-activity',
        ];

        yield 'the pair partner' => [self::B, [
            ...$shared,
            // Their own values form of the shared result, their edit form of it, their own pages
            '/en/custom-fields/result/{time}',
            'frame:/en/custom-fields/result/{time}',
            '/en/edit-time/{time}',
            'frame:/en/edit-time/{time}',
            '/en/time-added/{time}',
            '/en/custom-fields',
            '/en/puzzle-add',
            '/en/puzzle-add/{puzzle}',
            '/en/my-profile',
            '/en/export-puzzler-data/{b}/download/json',
            '/en/export-puzzler-data/{b}/download/csv',
            '/en/export-puzzler-data/{b}/download/xml',
        ]];
        yield 'another player' => [self::C, [...$shared, '/en/custom-fields', '/en/puzzle-add/{puzzle}']];
        yield 'a guest' => [null, $shared];
    }

    /**
     * @param list<string> $pages
     */
    #[DataProvider('provideViewers')]
    public function testNothingAnybodyElseOpensCarriesThePlayersOwnValues(null|string $viewer, array $pages): void
    {
        $browser = self::createClient();
        [$timeId, $secrets] = $this->pairResultWithValuesOfA();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        foreach ($pages as $page) {
            $inFrame = str_starts_with($page, 'frame:');
            $url = strtr($inFrame ? substr($page, 6) : $page, ['{time}' => $timeId, '{a}' => self::A, '{b}' => self::B, '{puzzle}' => CustomFieldsScenario::PUZZLE]);

            $browser->followRedirects();
            $browser->request('GET', $url, server: $inFrame ? ['HTTP_TURBO_FRAME' => 'modal-frame'] : []);
            $browser->followRedirects(false);

            self::assertResponseIsSuccessful($page);
            $this->assertCarriesNothingOfA((string) $browser->getInternalResponse()->getContent(), $secrets, $page);
        }
    }

    public function testTheApiCarriesNothingOfIt(): void
    {
        $browser = self::createClient();
        [$timeId, $secrets] = $this->pairResultWithValuesOfA();

        // The partner's token and the owner's own token alike: the API does not know custom fields at all
        foreach ([self::B, self::A] as $playerId) {
            PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));

            foreach (['/api/v1/me', '/api/v1/me/results', '/api/v1/me/results?type=duo', '/api/v1/me/statistics', '/api/v1/me/library', '/api/v1/puzzles/' . CustomFieldsScenario::PUZZLE] as $url) {
                $browser->request('GET', $url);

                self::assertResponseIsSuccessful($url);
                $content = (string) $browser->getResponse()->getContent();
                $this->assertCarriesNothingOfA($content, $secrets, $url);
                self::assertStringNotContainsStringIgnoringCase('custom', $content, $url);
            }

            $browser->request('GET', '/api/v1/me/results?type=duo');
            self::assertStringContainsString($timeId, (string) $browser->getResponse()->getContent(), 'The shared result itself is in both players\' results');
        }
    }

    public function testTheOwnerDoesSeeThem(): void
    {
        // What the canary looks for is really on the owner's page - otherwise it would prove nothing
        $browser = self::createClient();
        [$timeId] = $this->pairResultWithValuesOfA();
        TestingLogin::asPlayer($browser, self::A);

        $browser->request('GET', '/en/result/' . $timeId);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach ([self::FIELD_NAME, self::CHOICE_FIELD_NAME, self::TEXT_VALUE, self::CHOICE_VALUE, self::TAG_VALUE] as $own) {
            self::assertStringContainsString($own, $content);
        }
    }

    /**
     * Collection pages are often PUBLIC (docs/features/custom-fields.md "Collection items"). The owner of a public
     * collection wrote into their own fields on its puzzles - and nothing another player or a guest can open carries
     * a value, a field name or a field id: not the collection's page, the main collection, the library, the puzzle,
     * the profile, the lists around it.
     *
     * @return iterable<string, array{null|string, list<string>}>
     */
    public static function provideCollectionViewers(): iterable
    {
        $public = [
            '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC,
            '/en/puzzle-collection/' . self::COLLECTOR,
            '/en/puzzle-library/' . self::COLLECTOR,
            '/en/sell-swap-list/' . self::COLLECTOR,
            '/en/player-profile/' . self::COLLECTOR,
            '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01,
            '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02,
            '/en/marketplace',
        ];

        yield 'another player' => [self::A, [
            ...$public,
            // Their own pages: their own fields, their own items, their own export
            '/en/custom-fields',
            '/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_04,
            '/en/collection/' . CollectionFixture::COLLECTION_PRIVATE,
            '/en/puzzle-collection/' . self::A,
            '/en/puzzle-library/' . self::A,
            '/en/export-puzzler-data/' . self::A . '/library/json',
            '/en/export-puzzler-data/' . self::A . '/library/xml',
            '/en/export-puzzler-data/' . self::A . '/download/json',
        ]];
        yield 'a guest' => [null, $public];
    }

    /**
     * @param list<string> $pages
     */
    #[DataProvider('provideCollectionViewers')]
    public function testNothingAnybodyElseOpensCarriesTheOwnersValuesOnCollectionPuzzles(null|string $viewer, array $pages): void
    {
        $browser = self::createClient();
        $secrets = $this->publicCollectionWithValuesOfItsOwner();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        foreach ($pages as $page) {
            $browser->followRedirects();
            $browser->request('GET', $page);
            $browser->followRedirects(false);

            self::assertResponseIsSuccessful($page);
            $this->assertCarriesNothingOfA((string) $browser->getInternalResponse()->getContent(), $secrets, $page);
        }

        // The edit form of the owner's item is not there for anybody else
        $browser->request('GET', '/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_01);
        self::assertContains($browser->getResponse()->getStatusCode(), $viewer === null ? [302] : [404]);
    }

    /**
     * Not even the owner gets them on a collection page: the lists are shared between viewers. The owner's values are
     * in the item's edit form and in their own export - nowhere else.
     */
    public function testTheCollectorSeesThemInTheItemsEditFormAndTheExportOnly(): void
    {
        $browser = self::createClient();
        $secrets = $this->publicCollectionWithValuesOfItsOwner();
        TestingLogin::asPlayer($browser, self::COLLECTOR);

        foreach (
            [
                '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC,
                '/en/collection/' . CollectionFixture::COLLECTION_STRIPE_TREFL,
                '/en/puzzle-collection/' . self::COLLECTOR,
                '/en/puzzle-library/' . self::COLLECTOR,
                '/en/player-profile/' . self::COLLECTOR,
                '/en/my-profile',
                '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01,
                '/en/export-puzzler-data/' . self::COLLECTOR . '/download/json',
            ] as $page
        ) {
            $browser->followRedirects();
            $browser->request('GET', $page);
            $browser->followRedirects(false);

            self::assertResponseIsSuccessful($page);
            $this->assertCarriesNothingOfA((string) $browser->getInternalResponse()->getContent(), $secrets, $page);
        }

        // What the canary looks for is really there where the owner may see it - otherwise it would prove nothing
        foreach (['/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_01, '/en/export-puzzler-data/' . self::COLLECTOR . '/library/json'] as $own) {
            $browser->request('GET', $own);
            self::assertResponseIsSuccessful($own);
            $content = (string) $browser->getResponse()->getContent();

            foreach ([self::SHELF_VALUE, self::ITEM_TAG_VALUE] as $value) {
                self::assertStringContainsString($value, $content, $own);
            }
        }

        $browser->request('GET', '/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_01);
        self::assertStringContainsString(self::SHELF_FIELD_NAME, (string) $browser->getResponse()->getContent());
    }

    public function testTheApiCarriesNothingOfTheValuesOnCollectionPuzzles(): void
    {
        $browser = self::createClient();
        $secrets = $this->publicCollectionWithValuesOfItsOwner();

        // Another player's app reading the public collection, and the owner's own app alike
        foreach ([self::A, self::COLLECTOR] as $playerId) {
            OAuth2TestHelper::addBearerToken($browser, OAuth2TestHelper::createAccessToken(
                $browser,
                OAuth2ClientFixture::CONFIDENTIAL_CLIENT_ID,
                $playerId,
                ['profile:read', 'collections:read', 'results:read', 'statistics:read'],
            ));

            foreach (
                [
                    '/api/v1/players/' . self::COLLECTOR . '/collections',
                    '/api/v1/players/' . self::COLLECTOR . '/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/items',
                    '/api/v1/players/' . self::COLLECTOR . '/collections/default/items',
                    '/api/v1/me/collections',
                    '/api/v1/me/collections/default/items',
                    '/api/v1/me/library',
                ] as $url
            ) {
                $browser->request('GET', $url);

                self::assertResponseIsSuccessful($url);
                $content = (string) $browser->getResponse()->getContent();
                $this->assertCarriesNothingOfA($content, $secrets, $url);
                self::assertStringNotContainsStringIgnoringCase('custom_values', $content, $url);
                self::assertStringNotContainsStringIgnoringCase('customValues', $content, $url);
                self::assertStringNotContainsStringIgnoringCase('my_fields', $content, $url);
            }
        }

        // The owner's own token on their own collection: the items are there, their own fields are not
        $browser->request('GET', '/api/v1/me/collections/' . CollectionFixture::COLLECTION_PUBLIC . '/items');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString(CollectionItemFixture::ITEM_01, $content);
        $this->assertCarriesNothingOfA($content, $secrets, 'the owner\'s own items');
    }

    /**
     * @return list<string> everything of the collector's that must stay the collector's
     */
    private function publicCollectionWithValuesOfItsOwner(): array
    {
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::COLLECTOR, self::SHELF_FIELD_NAME, forResults: false, forCollectionItems: true);
        $mood = $scenario->field(self::COLLECTOR, self::CHOICE_FIELD_NAME, CustomFieldType::Choice, [self::CHOICE_VALUE, 'narwhal-cheerful'], forResults: false, forCollectionItems: true);
        $tags = $scenario->field(self::COLLECTOR, 'Pangolin labels', CustomFieldType::Tags, forResults: false, forCollectionItems: true);
        $stars = $scenario->field(self::COLLECTOR, 'Ocelot stars', CustomFieldType::Rating, forResults: false, forCollectionItems: true);
        // The viewer has a field of their own, with nothing in it
        $scenario->field(self::A, 'Plain notes', forResults: false, forCollectionItems: true);

        // ITEM_01 and ITEM_02 in the public collection, ITEM_12 in the (public) main collection, ITEM_18 in the other
        // public collection
        foreach ([CollectionItemFixture::ITEM_01, CollectionItemFixture::ITEM_02, CollectionItemFixture::ITEM_12, CollectionItemFixture::ITEM_18] as $itemId) {
            $scenario->setItemValues($itemId, self::COLLECTOR, [$shelf => self::SHELF_VALUE, $mood => self::CHOICE_VALUE, $tags => self::ITEM_TAG_VALUE, $stars => '4']);
        }

        return [
            self::SHELF_FIELD_NAME, self::CHOICE_FIELD_NAME, 'Pangolin labels', 'Ocelot stars',
            self::SHELF_VALUE, self::CHOICE_VALUE, 'narwhal-cheerful', self::ITEM_TAG_VALUE,
            $shelf, $mood, $tags, $stars,
        ];
    }

    /**
     * @return array{string, list<string>} the shared result, and everything of A's that must stay A's
     */
    private function pairResultWithValuesOfA(): array
    {
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::A, self::FIELD_NAME);
        $mood = $scenario->field(self::A, self::CHOICE_FIELD_NAME, CustomFieldType::Choice, [self::CHOICE_VALUE, 'narwhal-cheerful']);
        $tags = $scenario->field(self::A, 'Pangolin labels', CustomFieldType::Tags);
        // B has a field of their own, with nothing in it
        $scenario->field(self::B, 'Plain notes');

        $timeId = $scenario->result(self::A_USER, ['#player3']);
        $scenario->setValues($timeId, self::A, [$notes => self::TEXT_VALUE, $mood => self::CHOICE_VALUE, $tags => self::TAG_VALUE]);

        return [$timeId, [
            self::FIELD_NAME, self::CHOICE_FIELD_NAME, 'Pangolin labels',
            self::TEXT_VALUE, self::CHOICE_VALUE, 'narwhal-cheerful', self::TAG_VALUE,
            $notes, $mood, $tags,
        ]];
    }

    /**
     * @param list<string> $secrets
     */
    private function assertCarriesNothingOfA(string $content, array $secrets, string $where): void
    {
        self::assertNotSame('', $content, $where);

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $content, $where . ' carries "' . $secret . '"');
        }
    }
}
