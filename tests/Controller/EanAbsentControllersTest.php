<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Query\GetPuzzlesNeedingImprovement;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\PuzzleNeedingImprovement;
use SpeedPuzzling\Web\Tests\Controller\InternalApi\InternalApiRequests;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ImprovementFilter;
use SpeedPuzzling\Web\Value\ImprovementNeed;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * "The box has no barcode (EAN)" through its surfaces: the player's "Suggest a change" form, a moderator's review, the
 * internal API, the puzzle page and "Puzzles needing improvement".
 */
final class EanAbsentControllersTest extends WebTestCase
{
    use InternalApiRequests;

    // Approved, no EAN, no pending proposal
    private const string PUZZLE = PuzzleFixture::PUZZLE_9000;
    private const string URL = '/en/puzzle/' . self::PUZZLE . '/suggest-change';
    private const string FORM = 'form[name="propose_puzzle_changes_form"]';
    private const string MODERATOR = PlayerFixture::PLAYER_WITH_STRIPE;

    public function testTheFormOffersItOnlyForAPuzzleWithoutAnEan(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(self::FORM . ' [data-controller="ean-absent"] input[name="propose_puzzle_changes_form[eanAbsent]"][data-ean-absent-target="checkbox"]');
        self::assertSelectorExists(self::FORM . ' [data-ean-absent-target="codes"] input[name="propose_puzzle_changes_form[eans][0]"]');

        // PUZZLE_1000_03 has an EAN
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03 . '/suggest-change');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::FORM . ' input[name="propose_puzzle_changes_form[eanAbsent]"]');
    }

    public function testAnEanAndNoBarcodeAtOnceAreRefusedWithTheFormKept(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $form = $browser->request('GET', self::URL)->filter(self::FORM)->form();
        $form['propose_puzzle_changes_form[eans][0]'] = '4005555011897';
        self::checkbox($form, 'propose_puzzle_changes_form[eanAbsent]')->tick();
        $crawler = $browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A box without a barcode has no EAN', $crawler->filter(self::FORM)->text());
        $kept = $crawler->filter(self::FORM)->form()->getValues();
        self::assertSame('1', $kept['propose_puzzle_changes_form[eanAbsent]']);
        self::assertSame('4005555011897', $kept['propose_puzzle_changes_form[eans][0]']);
        self::assertSame(0, $this->changeRequestCount());
    }

    public function testAPlayerProposesItAndTheFieldWaitsLocked(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $form = $browser->request('GET', self::URL)->filter(self::FORM)->form();
        self::checkbox($form, 'propose_puzzle_changes_form[eanAbsent]')->tick();
        $browser->submit($form);

        self::assertResponseRedirects();
        self::assertSame(1, $this->changeRequestCount());
        self::assertTrue($this->database()->fetchOne('SELECT proposed_ean_absent FROM puzzle_change_request WHERE puzzle_id = :id', ['id' => self::PUZZLE]));

        // Opened again: the EAN waits for that review, shown locked with what waits - no checkbox, no EAN input
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(self::FORM . ' input[name="propose_puzzle_changes_form[eanAbsent]"]');
        self::assertSelectorNotExists(self::FORM . ' input[name="propose_puzzle_changes_form[eans][0]"]');
        self::assertStringContainsString('Waiting for review: no barcode on the box', $crawler->filter(self::FORM)->text());

        // A forged EAN for the locked field is refused, nothing more filed
        $values = $crawler->filter(self::FORM)->form()->getPhpValues();
        self::assertIsArray($values['propose_puzzle_changes_form']);
        $values['propose_puzzle_changes_form']['eans'] = ['4005555011897'];
        $browser->request('POST', self::URL, $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->changeRequestCount());
    }

    public function testAModeratorApprovesItInTheReviewAndThePuzzleLeavesTheEanList(): void
    {
        $browser = self::createClient();
        $this->makeModerator();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $form = $browser->request('GET', self::URL)->filter(self::FORM)->form();
        self::checkbox($form, 'propose_puzzle_changes_form[eanAbsent]')->tick();
        $browser->submit($form);
        $changeRequestId = $this->database()->fetchOne('SELECT id FROM puzzle_change_request WHERE puzzle_id = :id', ['id' => self::PUZZLE]);
        self::assertIsString($changeRequestId);

        TestingLogin::asPlayer($browser, self::MODERATOR);
        $url = '/admin/puzzle-change-requests/' . $changeRequestId;
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        $review = $crawler->filter('form[data-controller~="puzzle-record"]');
        self::assertStringContainsString('No barcode on the box', $review->text());
        // Prefilled with the proposal
        self::assertSame('1', $review->form()->getValues()['puzzle_record_form[eanAbsent]']);

        $browser->submit($review->form());
        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $puzzle = $this->puzzle();
        self::assertTrue($puzzle->isEanAbsent());
        self::assertSame(PuzzleReportStatus::Approved, self::getContainer()->get(PuzzleChangeRequestRepository::class)->get($changeRequestId)->status);

        // Off the EAN list, no hint, and the puzzle page says it where the EAN would be
        $query = self::getContainer()->get(GetPuzzlesNeedingImprovement::class);
        $ids = array_map(static fn (PuzzleNeedingImprovement $row): string => $row->puzzleId, $query->page(new ImprovementFilter(need: ImprovementNeed::Ean), 1000, 0));
        self::assertNotContains(self::PUZZLE, $ids);
        self::assertFalse($query->hintForPuzzle(self::PUZZLE, false)?->eanMissing);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/puzzle/' . self::PUZZLE);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#puzzleDetails', 'No barcode on the box');

        // "Suggest a change" now explains it and offers the EAN for a newer print
        $crawler = $browser->request('GET', self::URL);
        self::assertSelectorNotExists(self::FORM . ' input[name="propose_puzzle_changes_form[eanAbsent]"]');
        self::assertStringContainsString('Confirmed: this box has no barcode', $crawler->filter(self::FORM)->text());
    }

    public function testAModeratorsDirectEditSetsIt(): void
    {
        $browser = self::createClient();
        $this->makeModerator();
        TestingLogin::asPlayer($browser, self::MODERATOR);

        $url = '/admin/puzzles/' . self::PUZZLE . '/edit';
        $form = $browser->request('GET', $url)->filter('form[data-controller~="puzzle-record"]')->form();
        self::checkbox($form, 'puzzle_record_form[eanAbsent]')->tick();
        $browser->submit($form);

        self::assertResponseRedirects();
        self::assertTrue($this->puzzle()->isEanAbsent());

        // And an EAN typed in later clears it
        $form = $browser->request('GET', $url)->filter('form[data-controller~="puzzle-record"]')->form();
        self::assertSame('1', $form->getValues()['puzzle_record_form[eanAbsent]']);
        self::checkbox($form, 'puzzle_record_form[eanAbsent]')->untick();
        $form['puzzle_record_form[eans][0]'] = '4005555011897';
        $browser->submit($form);

        self::assertResponseRedirects();
        $puzzle = $this->puzzle();
        self::assertFalse($puzzle->isEanAbsent());
        self::assertSame('4005555011897', $puzzle->ean);
    }

    public function testTheInternalApiFilesAndApprovesIt(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/puzzle-change-requests', ['puzzleId' => self::PUZZLE, 'eanAbsent' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertIsString($answer['changeRequestId']);

        // Waits: the EAN is locked by it
        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-change-requests', ['puzzleId' => self::PUZZLE, 'ean' => ['4005555011897']]);
        self::assertResponseStatusCodeSame(409);

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-change-requests/' . $answer['changeRequestId'] . '/approve', ['selectedFields' => ['eanAbsent']]);
        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->puzzle()->isEanAbsent());
    }

    public function testTheInternalApiRefusesItWithAnEan(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-change-requests', ['puzzleId' => PuzzleFixture::PUZZLE_1000_03, 'eanAbsent' => true]);
        self::assertResponseStatusCodeSame(400);

        self::callInternalApi($browser, 'POST', '/internal-api/puzzle-change-requests', ['puzzleId' => self::PUZZLE, 'eanAbsent' => 'yes']);
        self::assertResponseStatusCodeSame(400);
    }

    private static function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function makeModerator(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $moderator = $entityManager->find(Player::class, self::MODERATOR);
        self::assertNotNull($moderator);
        $moderator->moderatorSince = TestClock::now()->modify('-1 month');
        $entityManager->flush();
        $entityManager->clear();
    }

    private function puzzle(): Puzzle
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return self::getContainer()->get(PuzzleRepository::class)->get(self::PUZZLE);
    }

    private function changeRequestCount(): int
    {
        $count = $this->database()->fetchOne('SELECT count(*) FROM puzzle_change_request WHERE puzzle_id = :id', ['id' => self::PUZZLE]);

        return is_numeric($count) ? (int) $count : -1;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
