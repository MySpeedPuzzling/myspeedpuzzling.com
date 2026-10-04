<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PuzzleChangeRequestControllerTest extends WebTestCase
{
    public function testListIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/admin/puzzle-change-requests');

        $this->assertResponseRedirects('/login?return=/admin/puzzle-change-requests');
    }

    public function testApproveIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);

        $this->assertResponseRedirects('/login');
    }

    public function testRejectIsNotAccessibleByAnonymous(): void
    {
        $browser = self::createClient();
        $browser->request('POST', '/admin/puzzle-change-requests/00000000-0000-0000-0000-000000000000/reject');

        $this->assertResponseRedirects('/login');
    }

    public function testTheReviewFormHoldsTheWholePuzzleWithTheProposalPrefilled(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[data-controller="change-request-review"]')->form();
        $values = $form->getValues();

        // Proposed by the player
        self::assertSame('Updated Puzzle Name', $values['review_puzzle_change_request_form[name]']);
        self::assertSame('1234567890123', $values['review_puzzle_change_request_form[ean]']);
        // Not proposed - the puzzle as it is now
        self::assertSame('RB-500-001', $values['review_puzzle_change_request_form[identificationNumber]']);
        self::assertSame('500', $values['review_puzzle_change_request_form[piecesCount]']);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $values['review_puzzle_change_request_form[manufacturerId]']);
        self::assertSame('keep', $values['review_puzzle_change_request_form[image]']);

        // The proposal is shown apart from the inputs, marked per field
        self::assertSelectorCount(2, '[data-change-request-review-target="field"][data-proposed]');
        self::assertSelectorExists('[data-label="Name"][data-proposed="Updated Puzzle Name"][data-current="Puzzle 1"]');
    }

    public function testApprovingSavesTheReviewersValuesForEveryField(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller="change-request-review"]')->form();

        $browser->submit($form, [
            // The proposed EAN has a wrong check digit - the reviewer fixes it
            'review_puzzle_change_request_form[ean]' => '4005556123452',
            'review_puzzle_change_request_form[alternativeName]' => 'Alternative Title',
            'review_puzzle_change_request_form[piecesCount]' => '1000',
        ]);

        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Updated Puzzle Name', $puzzle->name);
        self::assertSame('Alternative Title', $puzzle->alternativeName);
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertSame('4005556123452', $puzzle->ean);
        self::assertSame('RB-500-001', $puzzle->identificationNumber);

        $changeRequest = $browser->getContainer()->get(PuzzleChangeRequestRepository::class)->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        self::assertSame(PuzzleReportStatus::Approved, $changeRequest->status);
    }

    public function testAnInvalidEanIsRefusedWithTheFormKept(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller="change-request-review"]')->form();

        // Approved as proposed: the proposed EAN is no valid code
        $crawler = $browser->submit($form, [
            'review_puzzle_change_request_form[alternativeName]' => 'Typed By The Reviewer',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'Typed By The Reviewer',
            $crawler->filter('form[data-controller="change-request-review"]')->form()->getValues()['review_puzzle_change_request_form[alternativeName]'],
        );

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
    }

    public function testAReviewedRequestIsShownWithoutTheForm(): void
    {
        $browser = $this->signedInAdmin();

        $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_APPROVED);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-controller="change-request-review"]');
        self::assertSelectorTextContains('.card-body', 'Already Approved Name');
    }

    private function signedInAdmin(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }
}
