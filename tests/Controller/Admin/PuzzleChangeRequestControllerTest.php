<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Message\SuggestPuzzleName;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
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
        self::assertSame('1234567890123', $values['puzzle_record_form[ean]']);
        // Not proposed - the puzzle as it is now
        self::assertSame('RB-500-001', $values['puzzle_record_form[identificationNumber]']);
        self::assertSame('500', $values['puzzle_record_form[piecesCount]']);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $values['puzzle_record_form[manufacturerId]']);
        // No image was proposed - nothing to choose, only a drop area for a new one
        self::assertArrayNotHasKey('puzzle_record_form[image]', $values);
        self::assertSelectorExists('.file-drop-area input[name="puzzle_record_form[puzzlePhoto]"]');

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

        $form['puzzle_record_form[ean]'] = '4005556123452';
        $form['puzzle_record_form[piecesCount]'] = '1000';
        $values = $form->getPhpValues();
        self::assertIsArray($values['puzzle_record_form']);
        self::assertIsArray($values['puzzle_record_form']['names']);
        // The proposed EAN is no valid code - the reviewer fixes it, and adds a name ("+ Add a name")
        $values['puzzle_record_form']['names']['alternativeNames'] = [['name' => 'Alternative Title', 'language' => '']];
        $browser->request('POST', '/admin/puzzle-change-requests/' . PuzzleReportFixture::CHANGE_REQUEST_PENDING, $values);

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
        $fields['ean'] = '4005556123452';
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
        $changeRequestId = Uuid::uuid7()->toString();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SuggestPuzzleName(
            suggestionId: $changeRequestId,
            puzzleId: PuzzleFixture::PUZZLE_1000_02,
            playerId: PlayerFixture::PLAYER_REGULAR,
            name: $name,
            language: $language,
        ));

        return $changeRequestId;
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
