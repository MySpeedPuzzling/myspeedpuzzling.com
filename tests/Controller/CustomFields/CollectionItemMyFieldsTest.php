<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ArchiveCustomField;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionItemFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The owner's own fields in the edit form of a collection item (docs/features/custom-fields.md "Collection items") -
 * the only page that shows an item's values. The comment keeps working as it always did
 * (EditCollectionItemCommentControllerTest); this is about the fields beside it.
 *
 * ITEM_01: PLAYER_WITH_STRIPE's 500_01 in their public collection, with a comment.
 */
final class CollectionItemMyFieldsTest extends WebTestCase
{
    private const string OWNER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string SOMEBODY_ELSE = PlayerFixture::PLAYER_REGULAR;
    private const string ITEM = CollectionItemFixture::ITEM_01;
    private const string URL = '/en/edit-collection-item-comment/' . self::ITEM;
    private const string COMMENT = 'Beautiful landscape puzzle, one of my favorites!';

    public function testWithoutFieldsForCollectionPuzzlesTheFormIsTheCommentAndOneQuietLine(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        // A field for results only is no field of this form
        (new CustomFieldsScenario(self::getContainer()))->field(self::OWNER, 'Notes on a solve');

        $crawler = $browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('textarea[name="edit_collection_item_comment_form[comment]"]'));
        self::assertCount(0, $crawler->filter('[data-testid="collection-item-my-fields"]'));
        self::assertCount(0, $crawler->filter('[name^="my_fields["]'));
        self::assertStringNotContainsString('Notes on a solve', (string) $browser->getResponse()->getContent());
        $line = $crawler->filter('[data-testid="collection-item-no-my-fields"]');
        self::assertCount(1, $line);
        self::assertSame('/en/custom-fields', $line->filter('a')->attr('href'));
    }

    public function testTheOwnersFieldsForCollectionPuzzlesAreInTheFormWithWhatTheItemHolds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $price = $scenario->readyMade(self::OWNER, StandardCustomField::PricePaid);
        $condition = $scenario->readyMade(self::OWNER, StandardCustomField::Condition);
        $quality = $scenario->readyMade(self::OWNER, StandardCustomField::Quality);
        $liked = $scenario->readyMade(self::OWNER, StandardCustomField::HowILikedIt);
        // None of these is in the form: for results only, archived, somebody else's
        $forResults = $scenario->field(self::OWNER, 'Notes on a solve');
        $archived = $scenario->field(self::OWNER, 'Old field', forResults: false, forCollectionItems: true);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ArchiveCustomField($archived, self::OWNER));
        $scenario->field(self::SOMEBODY_ELSE, 'Field of somebody else', forResults: false, forCollectionItems: true);
        $scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top shelf', $price => '12.5', $condition => 'like_new', $quality => '4']);

        $crawler = $browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));
        // An explicit action: the form posts to its own route wherever it is rendered
        self::assertSame(self::URL, $crawler->filter('form[name="edit_collection_item_comment_form"]')->attr('action'));

        $block = $crawler->filter('form[name="edit_collection_item_comment_form"] [data-testid="collection-item-my-fields"]');
        self::assertCount(1, $block);
        self::assertCount(0, $crawler->filter('[data-testid="collection-item-no-my-fields"]'));
        // One quiet line: only the owner sees these fields, never the collection's page
        self::assertStringContainsString('Only you see these fields', $block->text());
        self::assertStringContainsString('not even when the collection is public', $block->text());
        self::assertCount(5, $block->filter('[data-custom-field]'));
        self::assertSame('top shelf', $block->filter('textarea[name="my_fields[' . $shelf . ']"]')->text());
        self::assertSame('12.5', $block->filter('input[name="my_fields[' . $price . ']"]')->attr('value'));
        self::assertSame('like_new', $block->filter('select[name="my_fields[' . $condition . ']"] option[selected]')->attr('value'));
        // A ready-made field is named in the page's language
        self::assertStringContainsString('Price paid', $block->text());
        self::assertStringContainsString('How I liked it', $block->text());

        // A rating: five radios and "No rating", the stored one checked - no JavaScript in it
        $stars = $block->filter('input[type="radio"][name="my_fields[' . $quality . ']"]');
        self::assertSame(['1', '2', '3', '4', '5', ''], $stars->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertSame(['4'], $block->filter('input[type="radio"][name="my_fields[' . $quality . ']"][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        // Not rated yet: "No rating" is the checked one
        self::assertSame([''], $block->filter('input[type="radio"][name="my_fields[' . $liked . ']"][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        // Every radio has its label, and the group its name
        foreach ($stars->each(static fn (Crawler $radio): string => (string) $radio->attr('id')) as $radioId) {
            self::assertCount(1, $block->filter('label[for="' . $radioId . '"]'), $radioId);
        }
        self::assertCount(2, $block->filter('[role="radiogroup"][aria-labelledby]'));

        $content = (string) $browser->getResponse()->getContent();

        foreach ([$forResults, $archived, 'Notes on a solve', 'Old field', 'Field of somebody else'] as $notHere) {
            self::assertStringNotContainsString($notHere, $content);
        }
    }

    public function testAskedForInTheModalFrameItIsTheSamePageWithItsExplicitAction(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top shelf']);

        // The form has no modal of its own; whatever asks for it gets the page, and the form names where it posts
        $crawler = $browser->request('GET', self::URL, server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        self::assertResponseIsSuccessful();
        self::assertSame(self::URL, $crawler->filter('form[name="edit_collection_item_comment_form"]')->attr('action'));
        self::assertSame('top shelf', $crawler->filter('textarea[name="my_fields[' . $shelf . ']"]')->text());
    }

    public function testSavingStoresTheFieldsAndTheCommentAndAnswersAsTheFormAlwaysDid(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $price = $scenario->readyMade(self::OWNER, StandardCustomField::PricePaid);
        $quality = $scenario->readyMade(self::OWNER, StandardCustomField::Quality);
        $tags = $scenario->readyMade(self::OWNER, StandardCustomField::MyTags);

        $this->submit($browser, 'A new comment', [$shelf => ' top shelf ', $price => '12,5', $quality => '5', $tags => 'gradient, cats']);

        self::assertResponseRedirects('/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        self::assertSame('A new comment', $this->comment());
        self::assertEquals([$shelf => 'top shelf', $price => 12.5, $quality => 5, $tags => ['gradient', 'cats']], $scenario->storedItemValues(self::ITEM));
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Comment was successfully updated.', (string) $browser->getResponse()->getContent());

        // Saved as it opens: nothing changes. Emptied: the values go, "No rating" takes the rating away
        $this->submit($browser, 'A new comment', []);
        self::assertResponseRedirects();
        self::assertEquals([$shelf => 'top shelf', $price => 12.5, $quality => 5, $tags => ['gradient', 'cats']], $scenario->storedItemValues(self::ITEM));

        $this->submit($browser, 'A new comment', [$shelf => '', $quality => '']);
        self::assertResponseRedirects();
        self::assertEquals([$price => 12.5, $tags => ['gradient', 'cats']], $scenario->storedItemValues(self::ITEM));
    }

    public function testAValueThatCanNotBeStoredAnswers422AtItsFieldAndSavesNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $price = $scenario->readyMade(self::OWNER, StandardCustomField::PricePaid);
        $bought = $scenario->readyMade(self::OWNER, StandardCustomField::BoughtOn);
        $scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top shelf']);

        $crawler = $this->submit($browser, 'A comment that must not be saved', [$shelf => 'bottom shelf', $price => 'a lot', $bought => '2026-03-01']);

        // Never a 200: Turbo Drive drops a 200 answer to a form submission
        self::assertResponseStatusCodeSame(422);
        $block = $crawler->filter('[data-testid="collection-item-my-fields"]');
        self::assertCount(1, $block->filter('.alert-danger'));
        // The message is at the field it is about, and only there
        $priceField = $block->filter('[data-custom-field="' . $price . '"]');
        self::assertStringContainsString('Enter a number', $priceField->filter('.invalid-feedback')->text());
        self::assertStringContainsString('is-invalid', (string) $priceField->filter('input')->attr('class'));
        self::assertCount(1, $block->filter('.invalid-feedback'));
        // What was typed is still there - everywhere
        self::assertSame('a lot', $priceField->filter('input')->attr('value'));
        self::assertSame('bottom shelf', $block->filter('textarea[name="my_fields[' . $shelf . ']"]')->text());
        self::assertSame('2026-03-01', $block->filter('input[name="my_fields[' . $bought . ']"]')->attr('value'));
        self::assertSame('A comment that must not be saved', $crawler->filter('textarea[name="edit_collection_item_comment_form[comment]"]')->text());
        // Nothing was saved: not the good fields, not the comment
        self::assertSame([$shelf => 'top shelf'], $scenario->storedItemValues(self::ITEM));
        self::assertSame(self::COMMENT, $this->comment());

        // Corrected, it is saved
        $this->submit($browser, 'A comment that is saved', [$shelf => 'bottom shelf', $price => '9', $bought => '2026-03-01']);
        self::assertResponseRedirects('/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        self::assertEquals([$shelf => 'bottom shelf', $price => 9, $bought => '2026-03-01'], $scenario->storedItemValues(self::ITEM));
        self::assertSame('A comment that is saved', $this->comment());
    }

    public function testACommentThatIsTooLongAnswers422AndKeepsWhatWasTypedIntoTheFields(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $quality = $scenario->readyMade(self::OWNER, StandardCustomField::Quality);

        $crawler = $this->submit($browser, str_repeat('a', 501), [$shelf => 'top shelf', $quality => '3']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('top shelf', $crawler->filter('textarea[name="my_fields[' . $shelf . ']"]')->text());
        self::assertSame(['3'], $crawler->filter('input[type="radio"][name="my_fields[' . $quality . ']"][checked]')->each(static fn (Crawler $radio): string => (string) $radio->attr('value')));
        self::assertNull($scenario->storedItemValues(self::ITEM));
        self::assertSame(self::COMMENT, $this->comment());
    }

    public function testAnythingSentInPlaceOfTheFieldsIsLeftOutOrRefusedNeverAnError(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $quality = $scenario->readyMade(self::OWNER, StandardCustomField::Quality);
        $ofSomebodyElse = $scenario->field(self::SOMEBODY_ELSE, 'Field of somebody else', forResults: false, forCollectionItems: true);
        $forResults = $scenario->field(self::OWNER, 'Notes on a solve');

        // Fields the form does not show are not for it; a list where a text belongs is no value
        $this->submit($browser, 'A comment', [$shelf => 'top shelf', $ofSomebodyElse => 'x', $forResults => 'y', 'not-an-id' => 'z', $quality => ['4']]);

        self::assertResponseRedirects();
        self::assertSame([$shelf => 'top shelf'], $scenario->storedItemValues(self::ITEM));

        // A rating that is no rating is a refusal at the field, like any other value
        $crawler = $this->submit($browser, 'A comment', [$quality => '6']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('1 to 5', $crawler->filter('[data-custom-field="' . $quality . '"] .invalid-feedback')->text());

        // Fields that are no array at all
        $this->submit($browser, 'A comment', null, ['my_fields' => 'nonsense']);
        self::assertResponseRedirects();
        self::assertSame([$shelf => 'top shelf'], $scenario->storedItemValues(self::ITEM));
    }

    public function testAnItemOfTheSystemCollectionGoesBackToItsPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::OWNER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);

        // ITEM_23: 500_02 in the owner's system collection
        $this->submit($browser, 'In the main collection', [$shelf => 'hall'], url: '/en/edit-collection-item-comment/' . CollectionItemFixture::ITEM_23);

        self::assertResponseRedirects('/en/puzzle-collection/' . self::OWNER);
        self::assertSame([$shelf => 'hall'], $scenario->storedItemValues(CollectionItemFixture::ITEM_23));
    }

    public function testSomebodyElsesItemIsNotThereAndNothingOfItIsWritten(): void
    {
        $browser = self::createClient();
        $scenario = new CustomFieldsScenario(self::getContainer());
        $shelf = $scenario->field(self::OWNER, 'Shelf', forResults: false, forCollectionItems: true);
        $own = $scenario->field(self::SOMEBODY_ELSE, 'My own shelf', forResults: false, forCollectionItems: true);
        $scenario->setItemValues(self::ITEM, self::OWNER, [$shelf => 'top shelf']);
        TestingLogin::asPlayer($browser, self::SOMEBODY_ELSE);

        $browser->request('GET', self::URL);
        self::assertResponseStatusCodeSame(404);

        $browser->request('POST', self::URL, ['edit_collection_item_comment_form' => ['comment' => 'Taken over'], 'my_fields' => [$own => 'mine', $shelf => 'mine']]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame([$shelf => 'top shelf'], $scenario->storedItemValues(self::ITEM));
        self::assertSame(self::COMMENT, $this->comment());
    }

    /**
     * The form as the browser sends it: opened, filled in, submitted.
     *
     * @param null|array<string, mixed> $myFields the fields to fill in (the others go as the form shows them);
     *                                            null = the request has no fields at all
     * @param array<string, mixed> $instead sent in place of what the form would send
     */
    private function submit(KernelBrowser $browser, string $comment, null|array $myFields, array $instead = [], string $url = self::URL): Crawler
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="edit_collection_item_comment_form"]')->form();
        $values = $form->getPhpValues();
        self::assertIsArray($values['edit_collection_item_comment_form'] ?? null);
        $values['edit_collection_item_comment_form']['comment'] = $comment;

        if ($myFields === null) {
            unset($values['my_fields']);
        } else {
            $shown = is_array($values['my_fields'] ?? null) ? $values['my_fields'] : [];
            $values['my_fields'] = [...$shown, ...$myFields];
        }

        return $browser->request('POST', $url, [...$values, ...$instead]);
    }

    private function comment(): null|string
    {
        $comment = self::getContainer()->get(Connection::class)->fetchOne('SELECT comment FROM collection_item WHERE id = :id', ['id' => self::ITEM]);

        return is_string($comment) ? $comment : null;
    }
}
