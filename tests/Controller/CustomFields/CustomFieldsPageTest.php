<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CustomFields;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\CustomFieldsScenario;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\CustomFieldType;
use SpeedPuzzling\Web\Value\StandardCustomField;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "My fields" - the manage page of a player's own fields (docs/features/custom-fields.md).
 */
final class CustomFieldsPageTest extends WebTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR;
    private const string OTHER_PLAYER = PlayerFixture::PLAYER_WITH_FAVORITES;

    public function testAGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/custom-fields');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideLocales(): iterable
    {
        yield 'en' => ['en', 'My fields'];
        yield 'cs' => ['cs', 'Moje pole'];
        yield 'de' => ['de', 'Meine Felder'];
        yield 'es' => ['es', 'Mis campos'];
        yield 'fr' => ['fr', 'Mes champs'];
        yield 'ja' => ['ja', 'マイ項目'];
    }

    #[DataProvider('provideLocales')]
    public function testThePageOpensInEveryLanguageAndAReadyMadeFieldIsNamedInIt(string $locale, string $title): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        (new CustomFieldsScenario(self::getContainer()))->readyMade(self::PLAYER, StandardCustomField::WhatToWorkOn);

        $crawler = $browser->request('GET', '/' . $locale . '/custom-fields');

        self::assertResponseIsSuccessful();
        self::assertSame($title, trim($crawler->filter('h1')->text()));
        self::assertStringContainsString('noindex', (string) $crawler->filter('meta[name="robots"]')->attr('content'));

        $label = self::getContainer()->get('translator')->trans(StandardCustomField::WhatToWorkOn->labelKey(), locale: $locale);
        self::assertSame($label, trim($crawler->filter('[data-testid="custom-field"] .fw-semibold')->text()));
        // No text left as a translation key
        self::assertStringNotContainsString('my_fields.', $crawler->filter('main')->count() > 0 ? $crawler->filter('main')->text() : $crawler->filter('body')->text());
    }

    public function testAnUnknownLanguageIsNoPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $browser->request('GET', '/xx/custom-fields');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnEmptyPageOffersTheReadyMadeFieldsAndTheFormOfAnOwnOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/custom-fields');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="custom-field"]'));
        self::assertCount(count(StandardCustomField::cases()), $crawler->filter('[data-testid="ready-made-field"]'));
        // A field for collection puzzles says plainly that filling it in arrives soon
        self::assertStringContainsString('filling them in arrives soon', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/condition"]')->text());
        self::assertStringNotContainsString('arrives soon', $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/what_to_work_on"]')->text());
        // The form posts to its own address, whatever page shows it
        self::assertSame('/en/custom-fields', $crawler->filter('form[name="custom_field_form"]')->attr('action'));
        // Phone first: a list of cards, never a table
        self::assertCount(0, $crawler->filter('[data-testid="custom-fields-page"] table'));
    }

    public function testAnOwnFieldIsAddedAndListed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $this->addOwnField($browser, ['name' => 'Where I puzzled', 'type' => 'choice', 'choices' => "table\nfloor\n", 'forResults' => '1']);

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        $card = $crawler->filter('[data-testid="custom-field"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Where I puzzled', $card->text());
        self::assertStringContainsString('One of a list', $card->text());
        self::assertStringContainsString('table, floor', $card->text());
        self::assertStringContainsString('The field was added.', $crawler->filter('body')->text());
        self::assertStringContainsString('Fields in use (1 of 20)', $crawler->filter('#my-fields')->text());
    }

    public function testAFieldThatCanNotBeAddedIsAnsweredWith422AndTheReason(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $scenario->field(self::PLAYER, 'Mood');

        $crawler = $this->addOwnField($browser, ['name' => '', 'type' => 'text', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);

        $crawler = $this->addOwnField($browser, ['name' => 'mood', 'type' => 'text', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a field with this name.', $crawler->filter('form[name="custom_field_form"]')->text());
        // What was typed is still there
        self::assertSame('mood', $crawler->filter('input[name="custom_field_form[name]"]')->attr('value'));

        $crawler = $this->addOwnField($browser, ['name' => 'Place', 'type' => 'choice', 'choices' => '', 'forResults' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Write at least one choice', $crawler->filter('form[name="custom_field_form"]')->text());

        $crawler = $this->addOwnField($browser, ['name' => 'Nowhere', 'type' => 'text']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Choose where you fill the field in.', $crawler->filter('form[name="custom_field_form"]')->text());

        self::assertSame(1, $scenario->fieldsOf(self::PLAYER));
    }

    public function testAReadyMadeFieldIsAddedWithOneTapAndIsNoLongerOffered(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $crawler = $browser->request('GET', '/en/custom-fields');

        $browser->submit($crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/my_tags"]')->form());

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        self::assertStringContainsString('My tags', $crawler->filter('[data-testid="custom-field"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="ready-made-field"][action$="/ready-made/my_tags"]'));
        // Its name is the page's, so there is nothing to edit
        self::assertCount(0, $crawler->filter('[data-testid="custom-field"] a[href*="/edit"]'));

        $browser->request('POST', '/en/custom-fields/ready-made/no_such_field', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFieldsMoveUpAndDown(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $scenario->field(self::PLAYER, 'First');
        $second = $scenario->field(self::PLAYER, 'Second');
        $scenario->field(self::PLAYER, 'Third');

        $crawler = $browser->request('GET', '/en/custom-fields');
        self::assertSame(['First', 'Second', 'Third'], $this->listedNames($crawler));
        // The ends have nowhere to go
        self::assertNotNull($crawler->filter('[data-testid="custom-field"]')->first()->filter('button[title="Move up"]')->attr('disabled'));
        self::assertNotNull($crawler->filter('[data-testid="custom-field"]')->last()->filter('button[title="Move down"]')->attr('disabled'));

        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'up']);
        self::assertResponseRedirects('/en/custom-fields#my-fields');
        self::assertSame(['Second', 'First', 'Third'], $this->listedNames($browser->request('GET', '/en/custom-fields')));

        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'down']);
        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'down']);
        // Past the end: nothing moves
        $browser->request('POST', '/en/custom-fields/' . $second . '/move', ['_token' => $this->token($crawler), 'direction' => 'down']);
        self::assertResponseRedirects();
        self::assertSame(['First', 'Third', 'Second'], $this->listedNames($browser->request('GET', '/en/custom-fields')));
    }

    public function testAFieldIsArchivedAndRestored(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $fieldId = (new CustomFieldsScenario(self::getContainer()))->field(self::PLAYER, 'Mood');
        $crawler = $browser->request('GET', '/en/custom-fields');

        $browser->request('POST', '/en/custom-fields/' . $fieldId . '/archive', ['_token' => $this->token($crawler)]);

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-testid="custom-field"]'));
        self::assertStringContainsString('Mood', $crawler->filter('[data-testid="archived-custom-field"]')->text());
        self::assertStringContainsString('What you wrote in it is kept', $crawler->filter('body')->text());

        $browser->submit($crawler->filter('[data-testid="archived-custom-field"] form')->form());

        self::assertResponseRedirects('/en/custom-fields');
        $crawler = $browser->followRedirect();
        self::assertSame(['Mood'], $this->listedNames($crawler));
        self::assertCount(0, $crawler->filter('[data-testid="archived-custom-field"]'));
    }

    public function testAnOwnFieldIsRenamedOnItsEditPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $fieldId = $scenario->field(self::PLAYER, 'Place', CustomFieldType::Choice, ['table', 'floor']);
        $scenario->field(self::PLAYER, 'Mood');

        $crawler = $browser->request('GET', '/en/custom-fields/' . $fieldId . '/edit');

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="custom_field_form"]');
        self::assertSame('/en/custom-fields/' . $fieldId . '/edit', $form->attr('action'));
        self::assertSame('Place', $form->filter('input[name="custom_field_form[name]"]')->attr('value'));
        self::assertSame("table\nfloor", $form->filter('textarea[name="custom_field_form[choices]"]')->text(normalizeWhitespace: false));
        // The type is told, never asked
        self::assertCount(0, $form->filter('[name="custom_field_form[type]"]'));
        self::assertStringContainsString('One of a list', $form->text());

        // Another field's name: refused, with what was typed
        $crawler = $browser->submit($form->form(['custom_field_form[name]' => 'MOOD']));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('You already have a field with this name.', $crawler->filter('form[name="custom_field_form"]')->text());

        $browser->submit($crawler->filter('form[name="custom_field_form"]')->form([
            'custom_field_form[name]' => 'Where',
            'custom_field_form[choices]' => "table\ndesk",
        ]));
        self::assertResponseRedirects('/en/custom-fields');
        $card = $browser->followRedirect()->filter('[data-testid="custom-field"]')->first();
        self::assertStringContainsString('Where', $card->text());
        self::assertStringContainsString('table, desk', $card->text());
    }

    public function testSomebodyElsesFieldAndAReadyMadeFieldHaveNoEditPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $theirs = $scenario->field(self::OTHER_PLAYER, 'Theirs');
        $readyMade = $scenario->readyMade(self::PLAYER, StandardCustomField::MyTags);
        $crawler = $browser->request('GET', '/en/custom-fields');

        // Nothing of the other player's is on the page
        self::assertStringNotContainsString('Theirs', (string) $browser->getResponse()->getContent());

        foreach ([$theirs, $readyMade] as $fieldId) {
            $browser->request('GET', '/en/custom-fields/' . $fieldId . '/edit');
            self::assertResponseStatusCodeSame(404);
        }

        $browser->request('POST', '/en/custom-fields/' . $theirs . '/archive', ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', '/en/custom-fields/' . $theirs . '/move', ['_token' => $this->token($crawler), 'direction' => 'up']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(1, $scenario->fieldsOf(self::OTHER_PLAYER));
    }

    public function testEveryButtonOfThePageNeedsItsToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $scenario = new CustomFieldsScenario(self::getContainer());
        $fieldId = $scenario->field(self::PLAYER, 'Mood');

        foreach (['/en/custom-fields/ready-made/my_tags', '/en/custom-fields/' . $fieldId . '/archive', '/en/custom-fields/' . $fieldId . '/move'] as $url) {
            $browser->request('POST', $url, ['_token' => 'wrong']);
            self::assertResponseStatusCodeSame(403, $url);
        }

        self::assertSame(1, $scenario->fieldsOf(self::PLAYER));
    }

    public function testTheProfileSettingsLinkToThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        self::assertSame('/en/custom-fields', $crawler->filter('#custom-fields a.btn')->attr('href'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function addOwnField(KernelBrowser $browser, array $fields): Crawler
    {
        $crawler = $browser->request('GET', '/en/custom-fields');
        $token = $crawler->filter('input[name="custom_field_form[_token]"]')->attr('value');

        return $browser->request('POST', '/en/custom-fields', ['custom_field_form' => [...$fields, '_token' => $token]]);
    }

    private function token(Crawler $page): string
    {
        return (string) $page->filter('form[action*="/custom-fields/"] input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return list<string>
     */
    private function listedNames(Crawler $page): array
    {
        return $page->filter('[data-testid="custom-field"] .fw-semibold')->each(static fn (Crawler $name): string => trim($name->text()));
    }
}
