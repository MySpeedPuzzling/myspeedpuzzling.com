<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\ProposesPuzzleNames;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FileFormField;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final class ProposeChangesControllerTest extends WebTestCase
{
    use ProposesPuzzleNames;

    private const string URL = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03 . '/suggest-change';

    public function testInvalidNewCodeIsRefusedWithAMessageOnTheField(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // The fixture's code 4005556789012 has a wrong check digit - it stays allowed, the new one is refused
        $crawler = $this->submit($browser, ean: '4005556789012, 45555011897');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('looks like 4005555011897 with two zeros missing', $crawler->filter('form[name="propose_puzzle_changes_form"]')->text());
        self::assertSame(0, $this->changeRequestCount());
    }

    public function testInvalidCodeThePuzzleAlreadyCarriesDoesNotBlockOtherChanges(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->submit($browser, ean: '4005556789012', name: 'Puzzle 8 - corrected name');

        self::assertResponseRedirects();
        self::assertSame(1, $this->changeRequestCount());
    }

    public function testTheNamesEditorProposesNamesAndTheMainTitlesLanguage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02 . '/suggest-change';

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="propose_puzzle_changes_form"] [data-controller="names-editor"]');

        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        self::assertSame('Puzzle 7', $form->getValues()['propose_puzzle_changes_form[names][name]']);
        self::assertSame('de', $form->getValues()['propose_puzzle_changes_form[names][alternativeNames][1][language]']);

        // What the editor sends after "+ Add another name" and the German name removed
        $values = $form->getPhpValues();
        self::assertIsArray($values['propose_puzzle_changes_form']);
        $values['propose_puzzle_changes_form']['names'] = [
            'name' => 'Puzzle 7',
            'nameLanguage' => '',
            'alternativeNames' => [
                ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
                ['name' => 'Jardín mágico', 'language' => 'es'],
            ],
        ];
        $browser->request('POST', $url, $values);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT proposed_name, proposed_alternative_names, proposed_name_language FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_02],
        );
        self::assertIsArray($row);
        self::assertSame('Puzzle 7', $row['proposed_name']);
        self::assertNull($row['proposed_name_language']);
        self::assertIsString($row['proposed_alternative_names']);
        self::assertSame([
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ['name' => 'Jardín mágico', 'language' => 'es'],
        ], json_decode($row['proposed_alternative_names'], true));
    }

    public function testTheNamesAsTheyAreProposeNothing(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02 . '/suggest-change';

        $crawler = $browser->request('GET', $url);
        $browser->submit($crawler->filter('form[name="propose_puzzle_changes_form"]')->form());

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_02],
        );
        self::assertSame(0, $count);
    }

    public function testAProposalOverAPuzzleChangedSinceTheFormWasLoadedIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02 . '/suggest-change';

        $crawler = $browser->request('GET', $url);
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();

        // A moderator removes the German name while the player edits the pieces only
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle SET alternative_names = :names WHERE id = :puzzleId',
            [
                'names' => json_encode([['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs']], JSON_THROW_ON_ERROR),
                'puzzleId' => PuzzleFixture::PUZZLE_1000_02,
            ],
        );

        $form['propose_puzzle_changes_form[piecesCount]'] = '1500';
        $crawler = $browser->submit($form);

        // Filed, it would have proposed the German name back as "added"
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This puzzle was changed while you were editing it', $crawler->filter('form[name="propose_puzzle_changes_form"]')->text());
        self::assertSame(0, $this->changeRequestCount(PuzzleFixture::PUZZLE_1000_02));
    }

    public function testAFormWithoutARecordVersionIsTreatedAsStale(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02 . '/suggest-change';

        $crawler = $browser->request('GET', $url);
        $values = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form()->getPhpValues();
        self::assertIsArray($values['propose_puzzle_changes_form']);

        // A form rendered by the release before has no version
        unset($values['propose_puzzle_changes_form']['recordVersion']);
        $values['propose_puzzle_changes_form']['piecesCount'] = '1500';
        $browser->request('POST', $url, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->changeRequestCount(PuzzleFixture::PUZZLE_1000_02));
    }

    public function testABrandNameNoBrandMatchesIsProposedAsANewBrand(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::URL);
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[brand]'] = 'Ravensburger Junior';
        $browser->submit($form);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT m.name, m.approved, pcr.created_manufacturer_name FROM puzzle_change_request pcr JOIN manufacturer m ON m.id = pcr.proposed_manufacturer_id WHERE pcr.puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_03],
        );
        self::assertSame(['name' => 'Ravensburger Junior', 'approved' => false, 'created_manufacturer_name' => 'Ravensburger Junior'], $row);
    }

    public function testAProposalIsFiledAgainstTheNamesThePlayerSaw(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02 . '/suggest-change';

        $crawler = $browser->request('GET', $url);
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[piecesCount]'] = '1500';
        $browser->submit($form);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_02);

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT proposed_pieces_count, proposed_alternative_names, original_alternative_names, original_name_language FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_02],
        );
        self::assertIsArray($row);
        self::assertSame(1500, $row['proposed_pieces_count']);
        // The names are no part of the proposal; the snapshot is what the form was loaded with
        self::assertNull($row['proposed_alternative_names']);
        self::assertNull($row['original_name_language']);
        self::assertIsString($row['original_alternative_names']);
        self::assertSame([
            ['name' => PuzzleFixture::NAME_CS_MAGIC_GARDEN, 'language' => 'cs'],
            ['name' => PuzzleFixture::NAME_DE_MAGIC_GARDEN, 'language' => 'de'],
        ], json_decode($row['original_alternative_names'], true));
    }

    public function testAWaitingNameSuggestionDoesNotHoldUpAProposal(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        self::proposeOtherName(PuzzleFixture::PUZZLE_1000_03, PlayerFixture::PLAYER_PRIVATE, 'Puzzle osm', 'cs');

        $this->submit($browser, ean: '4005556789012', name: 'Puzzle 8 - corrected name');

        self::assertResponseRedirects();
        self::assertSame(2, $this->changeRequestCount());
    }

    public function testAFieldAnotherWaitingProposalChangesIsRefusedTheRestIsFiled(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03 . '/suggest-change';

        $crawler = $browser->request('GET', $url);
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();

        // Somebody proposes the pieces while the player edits
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_03);
        $piecesBefore = $puzzle->piecesCount;
        $this->proposePieces(1500);

        $form['propose_puzzle_changes_form[piecesCount]'] = '2000';
        $form['propose_puzzle_changes_form[brandCodes][0]'] = 'RB-8';
        $crawler = $browser->submit($form);

        // The form comes back with the reason, the rest of what was typed, and the pieces as the puzzle has them
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->changeRequestCount(), 'Nothing filed yet');
        self::assertSelectorTextContains('form[name="propose_puzzle_changes_form"]', 'Another suggestion for Pieces Count is waiting for review');
        self::assertSelectorTextContains('form[name="propose_puzzle_changes_form"]', 'Waiting for review: 1500');
        self::assertSame('RB-8', $crawler->filter('input[name="propose_puzzle_changes_form[brandCodes][0]"]')->attr('value'));
        self::assertSame((string) $piecesBefore, $crawler->filter('input[name="propose_puzzle_changes_form[piecesCount]"]')->attr('value'));
        self::assertSame('hidden', $crawler->filter('input[name="propose_puzzle_changes_form[piecesCount]"]')->attr('type'));

        // Sent again as it came back: the brand code is filed next to the waiting pieces
        $browser->submit($crawler->filter('form[name="propose_puzzle_changes_form"]')->form());
        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03);
        self::assertSame(2, $this->changeRequestCount());

        // The form opens with both locked, the EAN and the photo still editable
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('[data-locked-field]'));
        self::assertSame('text', $crawler->filter('input[name="propose_puzzle_changes_form[eans][0]"]')->attr('type'));
        self::assertCount(1, $crawler->filter('input[name="propose_puzzle_changes_form[photo]"]'));
    }

    public function testNamesAreFiledWhileTheirFieldsWait(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $this->proposePieces(1500);

        $crawler = $browser->request('GET', self::URL);
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[names][name]'] = 'Puzzle 8 - corrected name';
        $browser->submit($form);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03);
        self::assertSame(2, $this->changeRequestCount(), 'Names only are filed regardless');
    }

    public function testAPuzzleWithAWaitingMergeRequestShowsThePendingProposalsInstead(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // PuzzleReportFixture: a pending merge request of PUZZLE_500_01 - every field waits
        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/suggest-change');
        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/suggest-change', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal-frame');
        self::assertSelectorNotExists('form[name="propose_puzzle_changes_form"]');
    }

    public function testEveryFieldWaitingShowsThePendingProposalsInstead(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_03);
        $this->propose(
            brand: ManufacturerFixture::MANUFACTURER_TREFL === $puzzle->manufacturer?->id->toString() ? ManufacturerFixture::MANUFACTURER_RAVENSBURGER : ManufacturerFixture::MANUFACTURER_TREFL,
            piecesCount: 1500,
            eans: EanList::fromStored('4005555011897'),
            brandCodes: BrandCodeList::fromStored('RB-8'),
            photo: true,
        );

        $browser->request('GET', self::URL);
        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03);
    }

    private function proposePieces(int $piecesCount): void
    {
        $this->propose(piecesCount: $piecesCount);
    }

    /**
     * A proposal of PUZZLE_1000_03 by another player, filed like the form does - every other field as the puzzle has it
     */
    private function propose(
        null|string $brand = null,
        null|int $piecesCount = null,
        null|EanList $eans = null,
        null|BrandCodeList $brandCodes = null,
        bool $photo = false,
    ): void {
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_1000_03);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SubmitPuzzleChangeRequest(
            changeRequestId: Uuid::uuid7()->toString(),
            puzzleId: PuzzleFixture::PUZZLE_1000_03,
            reporterId: PlayerFixture::PLAYER_PRIVATE,
            proposedName: $puzzle->name,
            proposedBrand: $brand ?? $puzzle->manufacturer?->id->toString(),
            proposedPiecesCount: $piecesCount ?? $puzzle->piecesCount,
            proposedEans: $eans ?? $puzzle->eans(),
            proposedBrandCodes: $brandCodes ?? $puzzle->brandCodes(),
            proposedPhoto: $photo ? new UploadedFile($this->boxPhoto(), 'box.jpg', 'image/jpeg', null, true) : null,
            originalAlternativeNames: $puzzle->alternativeNames(),
            originalNameLanguage: $puzzle->nameLanguage,
        ));
    }

    public function testARefusedProposalKeepsItsPhotoForTheNextSubmit(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        // The kept photo lives in the kernel's storage - one kernel for both submits
        $browser->disableReboot();

        $crawler = $browser->request('GET', self::URL);
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[eans][0]'] = '6000-5533';
        $photoField = $form['propose_puzzle_changes_form[photo]'];
        self::assertInstanceOf(FileFormField::class, $photoField);
        $photoField->upload($this->boxPhoto());
        $crawler = $browser->submit($form);

        // Refused for the code - the photo is kept, not chosen again
        self::assertResponseStatusCodeSame(422);
        $token = $crawler->filter('input[name="photo_stash[photo]"]')->attr('value');
        self::assertNotEmpty($token);

        // The next submit sends only the token: the proposal is filed with the photo
        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[eans][0]'] = '';
        $browser->submit($form);

        self::assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03);
        self::assertNotNull(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT proposed_image FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => PuzzleFixture::PUZZLE_1000_03],
        ));
    }

    private function boxPhoto(): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('box-', true) . '.jpg';
        $image = imagecreatetruecolor(400, 300);
        assert($image !== false);
        imagejpeg($image, $path);

        return $path;
    }

    private function submit(KernelBrowser $browser, string $ean, null|string $name = null): Crawler
    {
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="propose_puzzle_changes_form"]')->form();
        $form['propose_puzzle_changes_form[eans][0]'] = $ean;

        if ($name !== null) {
            $form['propose_puzzle_changes_form[names][name]'] = $name;
        }

        return $browser->submit($form);
    }

    private function changeRequestCount(string $puzzleId = PuzzleFixture::PUZZLE_1000_03): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM puzzle_change_request WHERE puzzle_id = :puzzleId',
            ['puzzleId' => $puzzleId],
        );

        return is_numeric($count) ? (int) $count : -1;
    }
}
