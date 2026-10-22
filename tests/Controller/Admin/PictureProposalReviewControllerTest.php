<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Admin;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Controller\Admin\DecidePictureProposalController;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/puzzle-approvals.md "Picture proposals": the queue's source / tag filters, the picture review grid
 * (moderators only, surest first) and its decisions - the existing approve / reject messages, one card at a time.
 */
final class PictureProposalReviewControllerTest extends WebTestCase
{
    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_03;

    private const string OTHER_PUZZLE = PuzzleFixture::PUZZLE_1000_04;

    private const array IMAGES = [
        PuzzleFixture::PUZZLE_1000_03 => 'box-1000-03-018d0003-cccc0001.jpg',
        PuzzleFixture::PUZZLE_1000_04 => 'box-1000-04-018d0003-cccc0002.jpg',
    ];

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        // One container for the whole test - the in-memory storage keeps what a request wrote
        $this->browser->disableReboot();

        foreach (self::IMAGES as $puzzleId => $image) {
            self::getContainer()->get(Connection::class)->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
            $this->filesystem()->write($image, 'original picture of ' . $puzzleId);
        }
    }

    public function testAnonymousIsSentToSignIn(): void
    {
        $this->browser->request('GET', '/admin/puzzle-change-requests/pictures');

        self::assertResponseRedirects('/login?return=/admin/puzzle-change-requests/pictures');
    }

    public function testAPlayerIsForbidden(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', '/admin/puzzle-change-requests/pictures');
        self::assertResponseStatusCodeSame(403);

        $this->browser->request('POST', '/admin/puzzle-change-requests/' . Uuid::uuid7()->toString() . '/picture-decision', ['decision' => 'approve']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheQueueFiltersBySourceAndTag(): void
    {
        $crop = $this->pipelineProposal(self::PUZZLE, 0.93);
        $this->signInModerator();

        $crawler = $this->browser->request('GET', '/admin/puzzle-change-requests?source=image_pipeline');
        self::assertResponseIsSuccessful();
        self::assertSame([$crop], $this->listedRequestIds($crawler->filter('.list-group-item')->extract(['href'])));
        self::assertSelectorTextContains('[data-role="source-badge"]', 'Image pipeline');
        self::assertSelectorTextContains('[data-role="confidence"]', '93.0 %');
        // The tabs keep the filter
        self::assertSelectorExists('a.nav-link[href*="tab=rejected"][href*="source=image_pipeline"]');

        $crawler = $this->browser->request('GET', '/admin/puzzle-change-requests?tag=artwork_crop');
        self::assertSame([$crop], $this->listedRequestIds($crawler->filter('.list-group-item')->extract(['href'])));

        // A player's proposals only - the fixtures' pending ones, not the crop
        $crawler = $this->browser->request('GET', '/admin/puzzle-change-requests?source=player');
        $listed = $this->listedRequestIds($crawler->filter('.list-group-item')->extract(['href']));
        self::assertNotContains($crop, $listed);
        self::assertNotEmpty($listed);

        // Unknown values switch the filter off instead of failing
        $this->browser->request('GET', '/admin/puzzle-change-requests?source=robot&tag=' . urlencode('not a tag'));
        self::assertResponseIsSuccessful();
    }

    public function testTheGridShowsTheSurestProposalFirstWithBothPictures(): void
    {
        $unsure = $this->pipelineProposal(self::OTHER_PUZZLE, 0.61);
        $sure = $this->pipelineProposal(self::PUZZLE, 0.98);
        $this->signInModerator();

        $crawler = $this->browser->request('GET', '/admin/puzzle-change-requests/pictures');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['picture-proposal-' . $sure, 'picture-proposal-' . $unsure],
            $crawler->filter('[data-testid="picture-proposal"]')->extract(['id']),
        );
        self::assertSelectorExists('#picture-proposal-' . $sure . ' [data-role="current-picture"] img[loading="lazy"]');
        self::assertSelectorExists('#picture-proposal-' . $sure . ' [data-role="proposed-picture"] img[loading="lazy"]');
        self::assertSelectorTextContains('#picture-proposal-' . $sure . ' [data-role="model-version"]', 'artwork-crop 1.0.0');
        self::assertSelectorExists('[data-controller="picture-review"]');
        self::assertSelectorTextContains('[data-role="key-hints"]', 'approve');
        // The player's proposal of a picture in the fixtures has no tag - not among the artwork crops
        self::assertSelectorTextContains('[data-role="picture-total"]', '2 pending');
    }

    public function testApprovingFromTheGridAppliesOnlyThePictureAndAnswersWithTheCard(): void
    {
        $changeRequestId = $this->pipelineProposal(self::PUZZLE, 0.95);
        $nameBefore = $this->puzzles()->get(self::PUZZLE)->name;
        $this->signInModerator();

        $form = $this->browser->request('GET', '/admin/puzzle-change-requests/pictures')
            ->filter('#picture-proposal-' . $changeRequestId . ' form:has(input[name="decision"][value="approve"])')
            ->form();
        $this->browser->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml']);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        $answer = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('<turbo-stream action="replace" target="picture-proposal-' . $changeRequestId . '">', $answer);
        self::assertStringContainsString('data-status="approved"', $answer);

        $changeRequest = $this->changeRequests()->get($changeRequestId);
        self::assertSame(PuzzleReportStatus::Approved, $changeRequest->status);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $changeRequest->reviewedBy?->id->toString());

        $puzzle = $this->puzzles()->get(self::PUZZLE);
        self::assertNotSame(self::IMAGES[self::PUZZLE], $puzzle->image);
        self::assertSame($nameBefore, $puzzle->name);
        // The old picture stays, and its archive copy was kept
        self::assertTrue($this->filesystem()->fileExists(self::IMAGES[self::PUZZLE]));
        self::assertNotNull($changeRequest->originalArchivePath);

        $decision = $this->decisionOf($changeRequestId, PuzzleModerationAction::ChangeRequestApproved);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $decision->decidedById?->toString());
        self::assertSame(MergeDecisionSource::AdminUi, $decision->source);
    }

    public function testRejectingWithoutJavascriptUsesTheDefaultReasonAndReturnsToTheGrid(): void
    {
        $changeRequestId = $this->pipelineProposal(self::PUZZLE, 0.7);
        $this->signInModerator();

        $form = $this->browser->request('GET', '/admin/puzzle-change-requests/pictures?page=1')
            ->filter('#picture-proposal-' . $changeRequestId . ' form:has(button[data-picture-review-action="reject"])')
            ->form();
        $this->browser->submit($form);

        self::assertResponseRedirects('/admin/puzzle-change-requests/pictures?tag=artwork_crop', 303);

        $changeRequest = $this->changeRequests()->get($changeRequestId);
        self::assertSame(PuzzleReportStatus::Rejected, $changeRequest->status);
        self::assertSame(DecidePictureProposalController::DEFAULT_REJECTION_REASON, $changeRequest->rejectionReason);
        self::assertSame(self::IMAGES[self::PUZZLE], $this->puzzles()->get(self::PUZZLE)->image);
        $this->decisionOf($changeRequestId, PuzzleModerationAction::ChangeRequestRejected);
    }

    public function testADecidedCardIsNotDecidedAgain(): void
    {
        $changeRequestId = $this->pipelineProposal(self::PUZZLE, 0.7);
        $this->signInModerator();

        $form = $this->browser->request('GET', '/admin/puzzle-change-requests/pictures')
            ->filter('#picture-proposal-' . $changeRequestId . ' form:has(input[name="decision"][value="approve"])')
            ->form();
        $this->browser->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseIsSuccessful();

        // The same card again (another tab) - refused, the decision stands
        $this->browser->submit($form, ['decision' => 'reject'], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('data-role="decision-message"', (string) $this->browser->getResponse()->getContent());
        self::assertSame(PuzzleReportStatus::Approved, $this->changeRequests()->get($changeRequestId)->status);
    }

    public function testAProposalOfAnOlderPictureCannotBeApprovedFromTheGrid(): void
    {
        $changeRequestId = $this->pipelineProposal(self::PUZZLE, 0.99, fingerprint: PuzzleImageFingerprint::of('an-older-picture.jpg'));
        $this->signInModerator();

        $crawler = $this->browser->request('GET', '/admin/puzzle-change-requests/pictures');
        self::assertSelectorExists('#picture-proposal-' . $changeRequestId . ' [data-role="picture-changed"]');
        self::assertSelectorExists('#picture-proposal-' . $changeRequestId . ' button[data-picture-review-action="approve"][disabled]');

        // Sent anyway - refused before anything is dispatched
        $form = $crawler->filter('#picture-proposal-' . $changeRequestId . ' form:has(input[name="decision"][value="approve"])')->form();
        $this->browser->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequests()->get($changeRequestId)->status);
    }

    public function testARequestProposingMoreThanAPictureIsDecidedOnItsOwnPageOnly(): void
    {
        $changeRequestId = $this->pipelineProposal(self::PUZZLE, 0.9, proposedName: 'A better name');
        $this->signInModerator();

        $crawler = $this->browser->request('GET', '/admin/puzzle-change-requests/pictures');
        $card = '#picture-proposal-' . $changeRequestId;
        self::assertSelectorTextContains($card . ' [data-role="also-proposes"]', 'Also proposes: Name');
        self::assertSelectorExists($card . ' [data-role="also-proposes"] a[href$="/admin/puzzle-change-requests/' . $changeRequestId . '"]');
        self::assertSelectorExists($card . ' button[data-picture-review-action="approve"][disabled]');
        self::assertSelectorExists($card . ' button[data-picture-review-action="reject"][disabled]');
        self::assertSelectorExists($card . ' button[data-role="reject-with-reason"][disabled]');
        self::assertSelectorExists($card . ' textarea[name="rejection_reason"][disabled]');

        // Sent anyway - neither approve (the name would be dropped) nor reject (the name would be refused) goes through
        foreach (['approve', 'reject'] as $decision) {
            $form = $crawler->filter($card . ' form:has(input[name="decision"][value="' . $decision . '"])')->first()->form();
            $this->browser->submit($form, [], ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('proposes more than a picture', (string) $this->browser->getResponse()->getContent());
        }

        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequests()->get($changeRequestId)->status);
    }

    public function testADecisionWithoutAValidTokenIsRefused(): void
    {
        $changeRequestId = $this->pipelineProposal(self::PUZZLE, 0.9);
        $this->signInModerator();

        $this->browser->request('POST', '/admin/puzzle-change-requests/' . $changeRequestId . '/picture-decision', [
            '_token' => 'not-the-token',
            'decision' => 'approve',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(PuzzleReportStatus::Pending, $this->changeRequests()->get($changeRequestId)->status);
    }

    private function signInModerator(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
    }

    private function pipelineProposal(string $puzzleId, float $confidence, null|string $fingerprint = null, null|string $proposedName = null): string
    {
        $puzzle = $this->puzzles()->get($puzzleId);
        $id = Uuid::uuid7();
        $proposedImage = 'proposal-' . $id->toString() . '.jpg';
        $this->filesystem()->write($proposedImage, 'cropped picture of ' . $puzzleId);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new PuzzleChangeRequest(
            id: $id,
            puzzle: $puzzle,
            reporter: self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_ADMIN),
            submittedAt: TestClock::now(),
            proposedName: $proposedName ?? $puzzle->name,
            proposedManufacturer: $puzzle->manufacturer,
            proposedPiecesCount: $puzzle->piecesCount,
            proposedEan: $puzzle->ean,
            proposedIdentificationNumber: $puzzle->identificationNumber,
            proposedImage: $proposedImage,
            proposedImageRatio: 1.25,
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $puzzle->ean,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
            source: PuzzleChangeRequestSource::ImagePipeline,
            tags: ['artwork_crop'],
            confidence: $confidence,
            modelVersion: 'artwork-crop 1.0.0',
            sourceImageFingerprint: $fingerprint ?? PuzzleImageFingerprint::of(self::IMAGES[$puzzleId]),
        ));
        $entityManager->flush();

        return $id->toString();
    }

    /**
     * @param array<mixed> $hrefs
     *
     * @return list<string>
     */
    private function listedRequestIds(array $hrefs): array
    {
        return array_values(array_map(
            static fn (mixed $href): string => is_string($href) ? basename($href) : '',
            $hrefs,
        ));
    }

    private function decisionOf(string $changeRequestId, PuzzleModerationAction $action): PuzzleModerationDecision
    {
        $decision = self::getContainer()->get(EntityManagerInterface::class)->getRepository(PuzzleModerationDecision::class)
            ->findOneBy(['changeRequestId' => Uuid::fromString($changeRequestId), 'action' => $action]);
        self::assertNotNull($decision);

        return $decision;
    }

    private function filesystem(): Filesystem
    {
        return self::getContainer()->get(Filesystem::class);
    }

    private function changeRequests(): PuzzleChangeRequestRepository
    {
        return self::getContainer()->get(PuzzleChangeRequestRepository::class);
    }

    private function puzzles(): PuzzleRepository
    {
        return self::getContainer()->get(PuzzleRepository::class);
    }
}
