<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The approval of a newly added puzzle: similar puzzles are shown as search results, not duplicates, and the
 * moderator approves the record with corrections - a new photo of the box included.
 */
final class PuzzleApprovalControllerTest extends WebTestCase
{
    private const string URL = '/admin/puzzle-approvals/' . PuzzleFixture::PUZZLE_UNAPPROVED;

    public function testSimilarPuzzlesFromOtherBrandsAreFoldedAwayAsLikelyDifferent(): void
    {
        $browser = $this->signedInAdmin();

        $browser->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'not necessarily duplicates');
        // The fixture's other 1000-piece "Puzzle N"s are all from other brands
        self::assertSelectorExists('details summary');
        self::assertSelectorTextContains('details summary', 'most likely different puzzles');
        self::assertSelectorExists('details form[action$="/merge"] input[name="target_puzzle"]');
    }

    public function testApprovingSavesTheCorrectionsTheNewPhotoAndTheNote(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', self::URL);
        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form([
            'approve_puzzle_form[alternativeName]' => 'Twenty',
            'approve_puzzle_form[brandChoice]' => 'approve',
            'approve_puzzle_form[note]' => 'New photo, the player\'s was blurry',
        ]);
        $photoField = $form['approve_puzzle_form[puzzlePhoto]'];
        self::assertInstanceOf(FileFormField::class, $photoField);
        $photoField->upload($this->photo()->getPathname());
        $browser->submit($form);

        self::assertResponseRedirects('/admin/puzzle-approvals');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertTrue($puzzle->approved);
        self::assertSame('Twenty', $puzzle->alternativeNames()->legacyAlternativeName());
        self::assertNotNull($puzzle->image);
        self::assertStringContainsString('puzzle-20-1000', $puzzle->image);
        self::assertSame(2.0, $puzzle->imageRatio);
        $browser->getContainer()->get(Filesystem::class)->delete($puzzle->image);

        $decision = $browser->getContainer()->get(EntityManagerInterface::class)
            ->getRepository(PuzzleModerationDecision::class)
            ->findOneBy([
                'puzzleId' => Uuid::fromString(PuzzleFixture::PUZZLE_UNAPPROVED),
                'action' => PuzzleModerationAction::PuzzleApproved,
            ]);
        self::assertNotNull($decision);
        self::assertSame('New photo, the player\'s was blurry', $decision->note);
        self::assertSame('upload', $decision->details['image'] ?? null);
    }

    /**
     * Approving without touching the single "Alternative name" field keeps every other name: the field is prefilled
     * with the one name the old forms knew (the Czech one, not simply the first), and applied unchanged it changes
     * nothing. Left empty it would remove that name.
     */
    public function testApprovingWithoutTouchingTheAlternativeNameKeepsEveryName(): void
    {
        $browser = $this->signedInAdmin();
        $names = [
            ['name' => 'Twenty Pieces', 'language' => null],
            ['name' => 'Dvacet dílků', 'language' => 'cs'],
        ];
        $entityManager = $browser->getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertNotNull($puzzle);
        $puzzle->changeNames($puzzle->name, $puzzle->nameLanguage, PuzzleNames::fromArray($names), new DateTimeImmutable());
        $entityManager->flush();
        $entityManager->clear();

        $crawler = $browser->request('GET', self::URL);
        $form = $crawler->filter('form[data-controller~="puzzle-record"]')->form();
        self::assertSame('Dvacet dílků', $form->getValues()['approve_puzzle_form[alternativeName]']);

        $browser->submit($form, ['approve_puzzle_form[brandChoice]' => 'approve']);

        self::assertResponseRedirects('/admin/puzzle-approvals');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertTrue($puzzle->approved);
        self::assertSame($names, $puzzle->alternativeNames()->toArray());
    }

    public function testANewBrandNeedsADecision(): void
    {
        $browser = $this->signedInAdmin();

        $crawler = $browser->request('GET', self::URL);
        $browser->submit($crawler->filter('form[data-controller~="puzzle-record"]')->form());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('body', 'Decide what happens to the new brand.');
        self::assertFalse($browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_UNAPPROVED)->approved);
    }

    public function testADroppedPhotoIsKeptWhenTheFormIsRefused(): void
    {
        $browser = $this->signedInAdmin();
        // The kept photo lives in the test's in-memory storage - it must survive between the requests
        $browser->disableReboot();

        $crawler = $browser->request('GET', self::URL);
        $values = $crawler->filter('form[data-controller~="puzzle-record"]')->form()->getPhpValues();

        // No brand decision - refused, the photo stays
        $crawler = $browser->request('POST', self::URL, $values, ['approve_puzzle_form' => ['puzzlePhoto' => $this->photo()]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $token = (string) $crawler->filter('input[name="photo_stash[puzzlePhoto]"]')->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        $fields = $values['approve_puzzle_form'];
        self::assertIsArray($fields);
        $fields['brandChoice'] = 'approve';
        $browser->request('POST', self::URL, [
            'approve_puzzle_form' => $fields,
            'photo_stash' => ['puzzlePhoto' => $token],
        ]);

        self::assertResponseRedirects('/admin/puzzle-approvals');

        $puzzle = $browser->getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_UNAPPROVED);
        self::assertNotNull($puzzle->image);
        self::assertSame(2.0, $puzzle->imageRatio);
        $browser->getContainer()->get(Filesystem::class)->delete($puzzle->image);
    }

    private function photo(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'approval_photo_') . '.jpg';
        $image = imagecreatetruecolor(40, 20);
        assert($image !== false);
        imagejpeg($image, $path);

        return new UploadedFile($path, 'box.jpg', 'image/jpeg', null, true);
    }

    private function signedInAdmin(): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        return $browser;
    }
}
