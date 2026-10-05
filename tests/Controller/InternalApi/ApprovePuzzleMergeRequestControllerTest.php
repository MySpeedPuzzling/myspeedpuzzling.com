<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Controller\InternalApi\ApprovePuzzleMergeRequestController;
use SpeedPuzzling\Web\Exceptions\PuzzleMergeRequestNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleMergeRequests;
use SpeedPuzzling\Web\Repository\PuzzleMergeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleMergeRequestControllerTest extends KernelTestCase
{
    // Reports PUZZLE_500_01 and PUZZLE_500_02
    private const string MERGE_REQUEST_ID = PuzzleReportFixture::MERGE_REQUEST_PENDING;

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    private const string SURVIVOR_ID = PuzzleFixture::PUZZLE_500_01;

    public function testDispatchesApprovalWithDecisionMetadata(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $response = $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'East of the Sun and West of the Moon',
            'mergedEan' => '850006234257',
            'mergedIdentificationNumber' => '03.20B',
            'mergedPiecesCount' => 500,
            'mergedManufacturerId' => null,
            'selectedImagePuzzleId' => self::SURVIVOR_ID,
            'decisionConfidence' => 'high',
            'decisionNote' => 'Identical artwork and piece count.',
        ]));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        self::assertSame(self::MERGE_REQUEST_ID, $dispatched->mergeRequestId);
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId, 'Reviewer comes from config, never from the request body');
        self::assertSame(self::SURVIVOR_ID, $dispatched->survivorPuzzleId);
        self::assertSame(['850006234257'], $dispatched->mergedEans?->codes());
        self::assertSame(['03.20B'], $dispatched->mergedBrandCodes?->codes());
        self::assertSame(500, $dispatched->mergedPiecesCount);
        self::assertNull($dispatched->mergedManufacturerId);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
        self::assertSame(MergeDecisionConfidence::High, $dispatched->decisionConfidence);
        self::assertSame('Identical artwork and piece count.', $dispatched->decisionNote);
    }

    public function testBlankOptionalStringsBecomeNull(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedEan' => '   ',
            'mergedPiecesCount' => 500,
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        // A blank EAN must not overwrite what the survivor already has
        self::assertNull($dispatched->mergedEans);
        self::assertNull($dispatched->decisionConfidence);
        self::assertNull($dispatched->decisionNote);
    }

    public function testPassesTheReviewersNames(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Kouzelné ráno',
            'mergedNameLanguage' => 'CS',
            'mergedAlternativeNames' => [
                ['name' => 'Magic Morning', 'language' => 'en'],
                ['name' => ' Magischer  Morgen ', 'language' => null],
            ],
            'mergedPiecesCount' => 500,
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        self::assertSame('cs', $dispatched->mergedNameLanguage);
        self::assertNotNull($dispatched->mergedAlternativeNames);
        self::assertSame([
            ['name' => 'Magic Morning', 'language' => 'en'],
            ['name' => 'Magischer Morgen', 'language' => null],
        ], $dispatched->mergedAlternativeNames->toArray());
    }

    public function testWithoutNamesTheMergeUnionsThem(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        // Not given: the handler takes the language the puzzles know for the main title
        self::assertFalse($dispatched->mergedNameLanguage);
        self::assertNull($dispatched->mergedAlternativeNames);
    }

    public function testAnExplicitNullLanguageIsEnglishOrNotKnown(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedNameLanguage' => null,
            'mergedPiecesCount' => 500,
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        self::assertNull($dispatched->mergedNameLanguage);
    }

    public function testRejectsNamesItCannotStore(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
            'mergedAlternativeNames' => [['name' => 'Fine', 'language' => 'no such language']],
        ]));
    }

    public function testRejectsRequestWithoutSurvivorPuzzle(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"survivorPuzzleId" is required.');

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));
    }

    public function testRejectsNonPositivePiecesCount(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"mergedPiecesCount" must be a positive integer.');

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 0,
        ]));
    }

    public function testRejectsUnknownConfidenceValue(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
            'decisionConfidence' => 'absolutely-certain',
        ]));
    }

    public function testAnUpperCaseSurvivorIdIsTheSamePuzzle(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => strtoupper(self::SURVIVOR_ID),
            'selectedImagePuzzleId' => strtoupper(PuzzleFixture::PUZZLE_500_02),
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        self::assertSame(self::SURVIVOR_ID, $dispatched->survivorPuzzleId);
        self::assertSame(PuzzleFixture::PUZZLE_500_02, $dispatched->selectedImagePuzzleId);
    }

    public function testRejectsASurvivorThatIsNotAnId(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"survivorPuzzleId" must be an id.');

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => 'puzzle-1',
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));
    }

    public function testRejectsASurvivorTheRequestDoesNotReport(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"survivorPuzzleId" must be one of the reported puzzles');

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => PuzzleFixture::PUZZLE_1000_01,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));
    }

    public function testAnUnknownMergeRequestIsNotFound(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(PuzzleMergeRequestNotFound::class);

        $controller('018e0001-0000-0000-0000-0000000000ff', $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));
    }

    public function testPassesTheRecordVersionsKeyedByTheLowerCaseId(): void
    {
        $dispatched = null;

        $controller = $this->controller($this->messageBusCapturing($dispatched));

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
            'recordVersions' => [
                strtoupper(PuzzleFixture::PUZZLE_500_01) => 'a1b2c3d4e5f60718',
                PuzzleFixture::PUZZLE_500_02 => '0918f7e6d5c4b3a2',
            ],
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        self::assertSame([
            PuzzleFixture::PUZZLE_500_01 => 'a1b2c3d4e5f60718',
            PuzzleFixture::PUZZLE_500_02 => '0918f7e6d5c4b3a2',
        ], $dispatched->recordVersions);
    }

    public function testRecordVersionsMustMapPuzzleIdsToVersions(): void
    {
        $controller = $this->controllerWithNeverDispatchingBus();

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
            'recordVersions' => ['a1b2c3d4e5f60718'],
        ]));
    }

    public function testAPuzzleChangedSinceItsVersionWasReadAnswersConflictAndMergesNothing(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $puzzleRepository = $container->get(PuzzleRepository::class);
        $survivorVersion = PuzzleRecordVersion::ofPuzzle($puzzleRepository->get(PuzzleFixture::PUZZLE_500_01));

        $controller = new ApprovePuzzleMergeRequestController(
            $container->get(MessageBusInterface::class),
            $container->get(GetPuzzleMergeRequests::class),
            PlayerFixture::PLAYER_ADMIN,
        );

        $response = $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
            'recordVersions' => [
                PuzzleFixture::PUZZLE_500_01 => $survivorVersion,
                // Read before somebody saved the duplicate
                PuzzleFixture::PUZZLE_500_02 => '0000000000000000',
            ],
        ]));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('changed after its recordVersion was read', (string) $response->getContent());

        $container->get(EntityManagerInterface::class)->clear();
        $puzzleRepository->get(PuzzleFixture::PUZZLE_500_02);
        self::assertSame(PuzzleReportStatus::Pending, $container->get(PuzzleMergeRequestRepository::class)->get(self::MERGE_REQUEST_ID)->status);
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $controller = $this->controller($this->messageBusExpectingNoDispatch(), reviewerPlayerId: '');

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));
    }

    private function controllerWithNeverDispatchingBus(): ApprovePuzzleMergeRequestController
    {
        return $this->controller($this->messageBusExpectingNoDispatch());
    }

    private function controller(MessageBusInterface $messageBus, string $reviewerPlayerId = self::REVIEWER_ID): ApprovePuzzleMergeRequestController
    {
        self::bootKernel();

        return new ApprovePuzzleMergeRequestController(
            $messageBus,
            self::getContainer()->get(GetPuzzleMergeRequests::class),
            $reviewerPlayerId,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/puzzle-merge-requests/' . self::MERGE_REQUEST_ID . '/approve',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function messageBusCapturing(mixed &$dispatched): MessageBusInterface
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        return $bus;
    }

    private function messageBusExpectingNoDispatch(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        return $bus;
    }
}
