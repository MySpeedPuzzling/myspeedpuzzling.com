<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\CloseOutdatedPuzzleRequests;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\ProposesPuzzleNames;
use SpeedPuzzling\Web\Tests\ChangesPuzzleRecords;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

final class PuzzleChangeRequestControllerTest extends WebTestCase
{
    use ProposesPuzzleNames;
    use ChangesPuzzleRecords;

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

        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form();
        $values = $form->getValues();

        // Proposed by the player
        self::assertSame('Updated Puzzle Name', $values['puzzle_record_form[names][name]']);
        self::assertSame('1234567890123', $values['puzzle_record_form[eans][0]']);
        // Not proposed - the puzzle as it is now
        self::assertSame('RB-500-001', $values['puzzle_record_form[brandCodes][0]']);
        self::assertSame('500', $values['puzzle_record_form[piecesCount]']);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $values['puzzle_record_form[manufacturerId]']);
        // No image was proposed - nothing to choose, only a drop area for a new one
        self::assertArrayNotHasKey('puzzle_record_form[image]', $values);
        self::assertSelectorExists('.file-drop-area input[name="puzzle_record_form[puzzlePhoto]"]');
        self::assertSelectorTextContains('[data-role="puzzle-added-by"]', 'Admin User');

        // The proposal is shown apart from the inputs, marked per field
        self::assertSelectorCount(2, '[data-puzzle-record-target="field"][data-proposed]');
        self::assertSelectorExists('[data-label="Names"][data-proposed*="Updated Puzzle Name"][data-current*="Puzzle 1"]');
        self::assertSelectorTextContains('[data-label="Names"] [data-role="proposed-value"]', 'Main title changed');
    }

    public function testApprovingSavesTheReviewersValuesForEveryField(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form();

        $form['puzzle_record_form[eans][0]'] = '4005556123452';
        $form['puzzle_record_form[piecesCount]'] = '1000';
        $values = $form->getPhpValues();
        self::assertIsArray($values['puzzle_record_form']);
        self::assertIsArray($values['puzzle_record_form']['names']);
        // The proposed EAN is no valid code - the reviewer fixes it, and adds a name ("+ Add another name")
        $values['puzzle_record_form']['names']['alternativeNames'] = [['name' => 'Alternative Title', 'language' => '']];
        $browser->request('POST', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING, $values);

        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Updated Puzzle Name', $puzzle->name);
        self::assertSame('Alternative Title', $puzzle->alternativeNames()->legacyAlternativeName());
        self::assertSame(1000, $puzzle->piecesCount);
        self::assertSame('4005556123452', $puzzle->ean);
        self::assertSame('RB-500-001', $puzzle->identificationNumber);

        $changeRequest = $browser->getContainer()->get(PuzzleChangeRequestRepository::class)->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        self::assertSame(PuzzleReportStatus::Approved, $changeRequest->status);
    }

    public function testADecidedRequestShowsWhatBecameOfEveryProposedChange(): void
    {
        $browser = $this->signedInAdmin();
        $url = '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING;

        // Proposed: the name and an EAN. The reviewer keeps the name, saves another EAN and changes the pieces
        $form = $browser->request('GET', $url)->filter('form[data-controller~="puzzle-record"]')->form();
        $form['puzzle_record_form[names][name]'] = 'Puzzle 1';
        $form['puzzle_record_form[eans][0]'] = '4005556123452';
        $form['puzzle_record_form[piecesCount]'] = '1000';
        $form['puzzle_record_form[note]'] = 'The box says 1000 pieces';
        $browser->submit($form);
        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1 + .badge', 'Approved in part');
        self::assertSelectorTextContains('[data-role="outcome-summary"]', '0 of 2 proposed changes applied, 1 saved differently, 1 not applied.');
        self::assertSelectorTextContains('[data-role="outcome-summary"]', 'The reviewer also changed: Pieces.');
        self::assertSelectorTextContains('li[data-result="not_applied"]', 'Updated Puzzle Name');
        self::assertSelectorTextContains('[data-label="EAN"][data-result="altered"]', '4005556123452');
        self::assertSelectorTextContains('[data-role="reviewer-changes"]', 'Pieces');
        self::assertSelectorTextContains('[data-role="decision-note"]', 'The box says 1000 pieces');
        self::assertSelectorTextContains('[data-role="puzzle-added-by"]', 'Admin User');
    }

    public function testARejectedRequestShowsEveryProposedChangeAsNotApplied(): void
    {
        $browser = $this->signedInAdmin();

        $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_REJECTED);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-result="applied"]');
        self::assertSelectorExists('[data-result="not_applied"]');
    }

    public function testAnApprovalWithoutARecordShowsTheProposalWithoutResults(): void
    {
        $browser = $this->signedInAdmin();

        $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_APPROVED);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1 + .badge', 'Approved');
        self::assertSelectorTextContains('main', 'This approval did not record what it applied');
        self::assertSelectorNotExists('[data-result="applied"], [data-result="not_applied"], [data-result="altered"]');
    }

    public function testAnInvalidEanIsRefusedWithTheFormKept(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form();

        // Approved as proposed: the proposed EAN is no valid code
        $crawler = $browser->submit($form, [
            'puzzle_record_form[names][name]' => 'Typed By The Reviewer',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'Typed By The Reviewer',
            $crawler->filter('form[data-controller~="puzzle-record"]')->form()->getValues()['puzzle_record_form[names][name]'],
        );

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertSame('Puzzle 1', $puzzle->name);
    }

    public function testADroppedPhotoIsKeptWhenTheFormIsRefusedAndUsedOnTheNextSubmit(): void
    {
        $browser = $this->signedInAdmin();
        // The kept photo lives in the test's in-memory storage - it must survive between the requests
        $browser->disableReboot();
        $url = '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING;

        $crawler = $browser->request('GET', $url);
        $values = $crawler->filter('form[data-controller~="puzzle-record"]')->form()->getPhpValues();

        // The proposed EAN is invalid - refused, but the photo stays
        $crawler = $browser->request('POST', $url, $values, ['puzzle_record_form' => ['puzzlePhoto' => $this->photo()]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $token = (string) $crawler->filter('input[name="photo_stash[puzzlePhoto]"]')->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        // Sent again with the EAN fixed and only the token - the kept photo becomes the puzzle's image
        $fields = $values['puzzle_record_form'];
        self::assertIsArray($fields);
        $fields['eans'] = ['4005556123452'];
        $browser->request('POST', $url, [
            'puzzle_record_form' => $fields,
            'photo_stash' => ['puzzlePhoto' => $token],
        ]);

        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($puzzle->image);

        $filesystem = $browser->getContainer()->get(Filesystem::class);
        self::assertTrue($filesystem->fileExists($puzzle->image));
        self::assertStringContainsString('updated-puzzle-name-500', $puzzle->image);
        self::assertSame(2.0, $puzzle->imageRatio);
        $filesystem->delete($puzzle->image);
    }

    public function testANamesProposalStartsTheEditorWithTheProposalAppliedAndMarked(): void
    {
        $browser = $this->signedInAdmin();
        $changeRequestId = $this->suggestName('Jardín mágico', 'es');

        $crawler = $browser->request('GET', '/admin/puzzle-change-requests/' . $changeRequestId);
        self::assertResponseIsSuccessful();

        $values = $crawler->filter('form[data-controller~="puzzle-record"]')->form()->getValues();
        self::assertSame('Puzzle 7', $values['puzzle_record_form[names][name]']);
        self::assertSame(PuzzleFixture::NAME_CS_MAGIC_GARDEN, $values['puzzle_record_form[names][alternativeNames][0][name]']);
        self::assertSame('Jardín mágico', $values['puzzle_record_form[names][alternativeNames][2][name]']);
        self::assertSame('es', $values['puzzle_record_form[names][alternativeNames][2][language]']);
        self::assertSame(PuzzleRecordVersion::ofPuzzle($this->puzzle(PuzzleFixture::PUZZLE_1000_02)), $values['puzzle_record_form[recordVersion]']);

        // Only the names were proposed
        self::assertSelectorCount(1, '[data-puzzle-record-target="field"][data-proposed]');
        self::assertSelectorTextContains('[data-label="Names"] [data-role="proposed-value"]', 'Added Jardín mágico (Spanish)');
    }

    public function testApprovingAppliesTheReviewersNames(): void
    {
        $browser = $this->signedInAdmin();
        $changeRequestId = $this->suggestName('Jardín mágico', 'es');
        $url = '/admin/puzzle-change-requests/' . $changeRequestId;

        $values = $browser->request('GET', $url)->filter('form[data-controller~="puzzle-record"]')->form()->getPhpValues();
        self::assertIsArray($values['puzzle_record_form']);

        // The reviewer keeps the proposed name, removes the German one and makes the Czech one the main title
        $values['puzzle_record_form']['names'] = [
            'name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN,
            'nameLanguage' => 'cs',
            'alternativeNames' => [
                ['name' => 'Puzzle 7', 'language' => ''],
                ['name' => 'Jardín mágico', 'language' => 'es'],
            ],
        ];
        $browser->request('POST', $url, $values);

        self::assertResponseRedirects('/admin/puzzle-change-requests');

        $puzzle = $this->puzzle(PuzzleFixture::PUZZLE_1000_02);
        self::assertSame(PuzzleFixture::NAME_CS_MAGIC_GARDEN, $puzzle->name);
        self::assertSame('cs', $puzzle->nameLanguage);
        self::assertSame([
            ['name' => 'Puzzle 7', 'language' => null],
            ['name' => 'Jardín mágico', 'language' => 'es'],
        ], $puzzle->alternativeNames);
    }

    public function testASaveOverAPuzzleChangedMeanwhileIsRefusedWithTheFormKept(): void
    {
        $browser = $this->signedInAdmin();
        $changeRequestId = $this->suggestName('Jardín mágico', 'es');
        $url = '/admin/puzzle-change-requests/' . $changeRequestId;

        $form = $browser->request('GET', $url)->filter('form[data-controller~="puzzle-record"]')->form();

        // A moderator edits the puzzle meanwhile
        self::renamePuzzle(PuzzleFixture::PUZZLE_1000_02, 'Puzzle 7 - edited');

        $crawler = $browser->submit($form, ['puzzle_record_form[piecesCount]' => '1001']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('form', 'This puzzle was changed while you were editing it.');
        self::assertSame('1001', $crawler->filter('form[data-controller~="puzzle-record"]')->form()->getValues()['puzzle_record_form[piecesCount]']);

        self::assertSame(1000, $this->puzzle(PuzzleFixture::PUZZLE_1000_02)->piecesCount);
        self::assertSame(
            PuzzleReportStatus::Pending,
            $browser->getContainer()->get(PuzzleChangeRequestRepository::class)->get($changeRequestId)->status,
        );
    }

    public function testAReviewedNamesProposalListsWhatItProposed(): void
    {
        $browser = $this->signedInAdmin();
        $changeRequestId = $this->suggestName('Jardín mágico', 'es');

        $browser->request('POST', '/admin/puzzle-change-requests/' . $changeRequestId . '/reject', [
            'rejection_reason' => 'Not on any box',
        ]);
        $browser->request('GET', '/admin/puzzle-change-requests/' . $changeRequestId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.col-lg-8', 'Jardín mágico (Spanish)');

        $browser->request('GET', '/admin/puzzle-change-requests?tab=rejected');
        self::assertSelectorTextContains('.list-group', 'Other names');
    }

    public function testAReviewedRequestIsShownWithoutTheForm(): void
    {
        $browser = $this->signedInAdmin();

        $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_APPROVED);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-controller~="puzzle-record"]');
        self::assertSelectorTextContains('.col-lg-8', 'Already Approved Name');
    }

    public function testARequestFiledOnAPuzzleMergedSinceSaysWhereItWasFiled(): void
    {
        $browser = $this->signedInAdmin();
        // The fixture's pending merge keeps PUZZLE_500_01 - the proposal with an image was filed on PUZZLE_500_02
        $browser->getContainer()->get(MessageBusInterface::class)->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: PuzzleReportFixture::MERGE_REQUEST_PENDING,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_01,
            mergedName: 'Puzzle 1',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: null,
            selectedImagePuzzleId: null,
        ));

        $browser->request('GET', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-controller~="puzzle-record"]');
        self::assertSelectorExists('[data-role="merged-from"] a[href="/admin/puzzles/' . PuzzleFixture::PUZZLE_500_02 . '/history"]');
        self::assertSelectorTextContains('h2 a[href*="' . PuzzleFixture::PUZZLE_500_01 . '"]', 'Puzzle 1');
    }

    public function testARequestThePuzzleAlreadyHadIsListedAsAlreadyDone(): void
    {
        $browser = $this->signedInAdmin();
        $container = $browser->getContainer();
        $puzzle = $container->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_03);
        $request = new PuzzleChangeRequest(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            reporter: $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR),
            submittedAt: new DateTimeImmutable(),
            proposedPiecesCount: $puzzle->piecesCount,
            originalName: $puzzle->name,
            originalPiecesCount: $puzzle->piecesCount - 1,
        );
        $container->get(EntityManagerInterface::class)->persist($request);
        $container->get(EntityManagerInterface::class)->flush();
        $container->get(MessageBusInterface::class)->dispatch(new CloseOutdatedPuzzleRequests());

        $browser->request('GET', '/admin/puzzle-change-requests?tab=outdated');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/puzzle-change-requests/' . $request->id->toString() . '"]');

        $browser->request('GET', '/admin/puzzle-change-requests/' . $request->id->toString());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1 ~ .badge', 'Already done');
        self::assertSelectorExists('[data-role="outdated"]');
        self::assertSelectorTextContains('.col-lg-4', 'Closed automatically');
        self::assertSelectorNotExists('form[data-controller~="puzzle-record"]');
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'review_photo_') . '.jpg';
        $image = imagecreatetruecolor(40, 20);
        assert($image !== false);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
    }

    private function suggestName(string $name, string $language): string
    {
        return self::proposeOtherName(PuzzleFixture::PUZZLE_1000_02, PlayerFixture::PLAYER_REGULAR, $name, $language);
    }

    private function puzzle(string $puzzleId): Puzzle
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return self::getContainer()->get(PuzzleRepository::class)->get($puzzleId);
    }

    private function signedInAdmin(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }
}
