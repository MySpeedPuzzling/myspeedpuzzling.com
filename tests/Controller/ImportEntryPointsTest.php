<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Where the spreadsheet import, its guide, "My data" and the unlisted puzzles are linked from
 * (docs/features/data-import/entry-points.md). Every one is a plain link: the pages read nothing for them (the
 * budgets of the add form and the profile are pinned by PuzzleAddPageQueryBudgetTest / PlayerProfileQueryBudgetTest).
 */
final class ImportEntryPointsTest extends WebTestCase
{
    private const string GUIDE = '/en/import-your-spreadsheet';
    private const string IMPORT = '/en/import';

    public function testUserMenuLeadsToMyDataAndTheImport(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/faq');

        self::assertResponseIsSuccessful();
        self::assertSame('/en/my-data', $crawler->filter('[data-testid="menu-my-data"]')->attr('href'));
        self::assertSame(self::IMPORT, $crawler->filter('[data-testid="menu-import"]')->attr('href'));
        self::assertStringContainsString('Import a spreadsheet', $crawler->filter('[data-testid="menu-import"]')->text());
    }

    public function testGuestGetsNoMenuEntryButTheFooterLeadsToTheGuide(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');

        self::assertCount(0, $crawler->filter('[data-testid="menu-import"]'));
        self::assertCount(0, $crawler->filter('[data-testid="menu-my-data"]'));
        self::assertCount(1, $crawler->filter('footer .footer-columns a[href="' . self::GUIDE . '"]'));
    }

    public function testFaqAnswersTheSpreadsheetQuestion(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/faq');

        self::assertResponseIsSuccessful();
        $item = $crawler->filter('#import-spreadsheet');
        self::assertCount(1, $item);
        self::assertStringContainsString('spreadsheet', $item->filter('.accordion-button')->text());
        self::assertCount(1, $item->filter('.accordion-body a[href="' . self::GUIDE . '"]'));
    }

    public function testExportPageOffersToBringDataIn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="bring-data-in"]');
        self::assertStringContainsString('Bring data in', $card->filter('h2')->text());
        self::assertSame([self::IMPORT, self::GUIDE], $card->filter('a')->extract(['href']));
        // The way to the table stays
        self::assertSame('/en/my-data', $crawler->filter('[data-testid="my-data-link"]')->attr('href'));
    }

    public function testAddResultPageHasOneQuietLinkOutsideTheForm(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');

        self::assertResponseIsSuccessful();
        $line = $crawler->filter('[data-testid="add-form-import"]');
        self::assertCount(1, $line);
        self::assertSame([self::GUIDE], $line->filter('a')->extract(['href']));
        // The form is all there next to it
        $form = $crawler->filter('main form[method="post"]');
        self::assertGreaterThan(0, $form->count());
        self::assertGreaterThan(0, $form->filter('button[type="submit"]')->count());
        self::assertGreaterThan(0, $form->filter('input[type="file"]')->count(), 'The photo field - it is the add form');
        // Never part of a form: it can not be submitted with it, nor change what the form sends
        self::assertCount(0, $crawler->filter('form [data-testid="add-form-import"]'));
        self::assertCount(0, $line->filter('input, button, select, textarea'));
    }

    public function testGettingStartedGuideHasTheImportAsAnItemOfItsOwn(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/getting-started');

        self::assertResponseIsSuccessful();
        // An item of its own, named like on the welcome screen
        $item = $crawler->filter('#import');
        self::assertStringContainsString('Import your data from elsewhere', $item->filter('h2')->text());
        self::assertSame([self::GUIDE], $item->filter('a')->extract(['href']));
        self::assertStringNotContainsString('import_guide.', $item->text());
        // The six steps keep their numbers; nothing is tucked under "Track" any more
        self::assertCount(0, $crawler->filter('#track a[href="' . self::GUIDE . '"]'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/getting-started');
        self::assertSame([self::IMPORT, self::GUIDE], $crawler->filter('#import a')->extract(['href']));
    }

    public function testMyDataLeadsToTheUnlistedPuzzlesAndTheImport(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/my-data');

        self::assertResponseIsSuccessful();
        self::assertSame('/en/unlisted-puzzles', $crawler->filter('[data-testid="my-data-unlisted"]')->attr('href'));
        self::assertSame(self::IMPORT, $crawler->filter('[data-testid="my-data-import"]')->attr('href'));
    }

    public function testOwnProfileMenuAndLibraryLeadToMyDataAndTheImport(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('main .dropdown-menu a[href="/en/my-data"]')->count());
        self::assertGreaterThan(0, $crawler->filter('main .dropdown-menu a[href="' . self::IMPORT . '"]')->count());

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertSame('/en/my-data?tab=puzzles', $crawler->filter('[data-testid="library-my-data"]')->attr('href'));
        self::assertSame(self::IMPORT, $crawler->filter('[data-testid="library-import"]')->attr('href'));
    }

    public function testSomebodyElsesProfileAndLibraryShowNoneOfIt(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main a[href="/en/my-data"]'));
        self::assertCount(0, $crawler->filter('main a[href="' . self::IMPORT . '"]'));

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="library-my-data"], [data-testid="library-import"], [data-testid="empty-list-import"]'));
    }

    /**
     * A newcomer with nothing yet: every empty place names the spreadsheet path next to "add a puzzle".
     */
    public function testNewcomerIsShownTheSpreadsheetPathWhereverThereIsNothingYet(): void
    {
        $browser = self::createClient();
        $playerId = $this->newcomer($browser);
        TestingLogin::asPlayer($browser, $playerId);

        // The first screen after signing up
        $crawler = $browser->request('GET', '/welcome');
        self::assertResponseIsSuccessful();
        $choices = $crawler->filter('.onboarding-welcome a.onboarding-choice');
        self::assertCount(4, $choices, 'Log a puzzle, stopwatch, profile, import - equals');
        $import = $crawler->filter('[data-testid="welcome-import"]');
        self::assertSame(self::IMPORT, $import->attr('href'));
        self::assertStringContainsString('Import your data from elsewhere', $import->text());
        self::assertSame($import->attr('href'), $choices->last()->attr('href'));
        self::assertStringContainsString('btn-outline-primary', (string) $import->attr('class'));
        self::assertStringContainsString('btn-primary', (string) $choices->first()->attr('class'));

        // The Getting started card on the Hub: the steps are derived as before (1 of 5 - the account)
        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertCount(5, $crawler->filter('.getting-started .getting-started-steps > li'));
        self::assertCount(1, $crawler->filter('.getting-started .getting-started-step.is-done'));
        self::assertSame([self::GUIDE], $crawler->filter('.getting-started [data-testid="getting-started-import"] a')->extract(['href']));

        // Own profile: the same card, open; the statistics without a result say it too
        $crawler = $browser->request('GET', '/en/player-profile/' . $playerId);
        self::assertResponseIsSuccessful();
        self::assertSame([self::GUIDE], $crawler->filter('.getting-started [data-testid="getting-started-import"] a')->extract(['href']));

        $crawler = $browser->request('GET', '/en/player-statistics/' . $playerId);
        self::assertResponseIsSuccessful();
        self::assertSame([self::GUIDE], $crawler->filter('[data-testid="empty-results-import"] a')->extract(['href']));

        // The collection and the wish list
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . $playerId);
        self::assertResponseIsSuccessful();
        self::assertSame([self::GUIDE], $crawler->filter('[data-testid="empty-list-import"] a')->extract(['href']));

        $crawler = $browser->request('GET', '/en/wish-list/' . $playerId);
        self::assertResponseIsSuccessful();
        self::assertSame([self::GUIDE], $crawler->filter('[data-testid="empty-list-import"] a')->extract(['href']));
    }

    /**
     * Signing up from the import's guide: the welcome screen stays the landing, with the import first and highlighted.
     */
    public function testSignUpFromTheGuideLandsOnTheWelcomeScreenWithTheImportFirst(): void
    {
        $browser = self::createClient();
        // Registration is throttled per IP and the limiter's cache outlives a test
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register?return=/en/import');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Create account')->form();
        self::assertSame('/en/import', $crawler->filter('form input[type="hidden"][name="return"]')->attr('value'));
        $terms = $form[$form->getName() . '[termsAccepted]'];
        self::assertInstanceOf(ChoiceFormField::class, $terms);
        $terms->tick();

        $browser->submit($form, [
            $form->getName() . '[email]' => sprintf('import.guide+%s@example.com', bin2hex(random_bytes(4))),
            $form->getName() . '[plainPassword]' => 'a-properly-long-passphrase',
        ]);

        self::assertResponseRedirects('/welcome?return=/en/import');
        $crawler = $browser->followRedirect();
        self::assertResponseIsSuccessful();

        $choices = $crawler->filter('.onboarding-welcome a.onboarding-choice');
        self::assertCount(4, $choices, 'The usual choices stay');
        $import = $crawler->filter('[data-testid="welcome-import"]');
        self::assertCount(1, $import);
        self::assertSame(self::IMPORT, $import->attr('href'));
        self::assertSame(self::IMPORT, $choices->first()->attr('href'));
        self::assertStringContainsString('btn-primary', (string) $import->attr('class'));
        self::assertCount(1, $crawler->filter('.onboarding-welcome a.onboarding-choice.btn-primary'), 'One highlighted choice');
    }

    /**
     * A return that is not the import (or not a path of ours) changes nothing on the welcome screen.
     */
    public function testWelcomeScreenIgnoresAnyOtherReturn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['//evil.example/en/import', 'https://evil.example/en/import', '/en/import/new', '/en/hub'] as $return) {
            $crawler = $browser->request('GET', '/welcome?return=' . rawurlencode($return));
            self::assertResponseIsSuccessful();
            $import = $crawler->filter('[data-testid="welcome-import"]');
            self::assertSame(self::IMPORT, $import->attr('href'), $return);
            self::assertStringContainsString('btn-outline-primary', (string) $import->attr('class'), $return);
            self::assertSame('/en/puzzle-add', $crawler->filter('.onboarding-welcome a.onboarding-choice')->first()->attr('href'), $return);
        }
    }

    public function testImportsHomeWelcomesAFirstTimeVisitor(): void
    {
        $browser = self::createClient();
        $playerId = $this->newcomer($browser);
        TestingLogin::asPlayer($browser, $playerId);

        $crawler = $browser->request('GET', self::IMPORT);

        self::assertResponseIsSuccessful();
        $first = $crawler->filter('[data-testid="no-imports"]');
        self::assertCount(1, $first);
        self::assertCount(3, $first->filter('li'), 'A file, a Google Sheets link, Puzzle Tracker');
        self::assertStringContainsString('Puzzle Tracker', $first->text());
        self::assertStringNotContainsString('No imports yet', $crawler->filter('main')->text());

        $privacy = $first->filter('[data-testid="import-privacy"]')->text();
        self::assertStringContainsString('Only you see your file', $privacy);
        self::assertStringContainsString('30 days', $privacy);
        self::assertStringContainsString('Nothing is added to your profile until', $privacy);

        self::assertSame(self::GUIDE, $crawler->filter('[data-testid="import-guide-link"]')->attr('href'));
        self::assertSame('/en/import/new', $crawler->filter('[data-testid="import-a-spreadsheet"]')->attr('href'));
        self::assertStringNotContainsString('import_guide.', $crawler->filter('main')->text());
    }

    /**
     * Somebody's empty collection is not an invitation to import.
     */
    public function testAnotherPlayersEmptyListsDoNotOfferTheImport(): void
    {
        $browser = self::createClient();
        $playerId = $this->newcomer($browser);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (['/en/puzzle-collection/', '/en/wish-list/', '/en/player-profile/', '/en/player-statistics/'] as $page) {
            $crawler = $browser->request('GET', $page . $playerId);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-testid="empty-list-import"], [data-testid="empty-results-import"], [data-testid="getting-started-import"]'), $page);
        }
    }

    private function newcomer(KernelBrowser $browser): string
    {
        $player = new Player(
            Uuid::uuid7(),
            'newcomer8',
            'msp|' . Uuid::uuid7()->toString(),
            'Nora Newcomer',
            TestClock::now(),
        );

        $entityManager = $browser->getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $entityManager->persist($player);
        $entityManager->flush();

        return $player->id->toString();
    }
}
