<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\InternalApi\ApprovePuzzleChangeRequestController;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleChangeRequestControllerTest extends KernelTestCase
{
    private const string CHANGE_REQUEST_ID = '01a0db4b-0db4-7207-b080-5ba0e2549720';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    private const string PUZZLE_ID = '018d0003-0000-0000-0000-000000000001';

    public function testDispatchesTheApprovalWithTheSelectedFields(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $response = (new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(), self::REVIEWER_ID))(
            self::CHANGE_REQUEST_ID,
            $this->jsonRequest(['selectedFields' => [], 'decisionNote' => 'Already fixed by the Edcu → Educa merge']),
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(ApprovePuzzleChangeRequest::class, $dispatched);
        self::assertSame(self::CHANGE_REQUEST_ID, $dispatched->changeRequestId);
        self::assertSame(self::PUZZLE_ID, $dispatched->puzzleId, 'The approval locks the puzzle of the change request');
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId, 'Reviewer comes from config, never from the request body');
        self::assertSame([], $dispatched->selectedFields);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
        self::assertSame('Already fixed by the Edcu → Educa merge', $dispatched->decisionNote);
    }

    public function testTheNamesCanBeCorrectedForASelectedField(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        (new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(), self::REVIEWER_ID))(
            self::CHANGE_REQUEST_ID,
            $this->jsonRequest([
                'selectedFields' => ['alternativeNames', 'nameLanguage'],
                'alternativeNames' => [['name' => 'Kruh barev: Mušle', 'language' => 'cs'], ['name' => 'Seashells']],
                'nameLanguage' => null,
            ]),
        );

        self::assertInstanceOf(ApprovePuzzleChangeRequest::class, $dispatched);
        self::assertSame(
            [['name' => 'Kruh barev: Mušle', 'language' => 'cs'], ['name' => 'Seashells', 'language' => null]],
            $dispatched->alternativeNamesOverride?->toArray(),
        );
        self::assertNull($dispatched->nameLanguageOverride);
    }

    public function testWithoutCorrectionsTheProposalIsApplied(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        (new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(), self::REVIEWER_ID))(
            self::CHANGE_REQUEST_ID,
            $this->jsonRequest(['selectedFields' => ['alternativeNames']]),
        );

        self::assertInstanceOf(ApprovePuzzleChangeRequest::class, $dispatched);
        self::assertNull($dispatched->alternativeNamesOverride);
        self::assertFalse($dispatched->nameLanguageOverride);
    }

    public function testACorrectionOfAFieldNotSelectedIsRefused(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(), self::REVIEWER_ID);

        foreach (
            [
            ['selectedFields' => ['name'], 'alternativeNames' => [['name' => 'Seashells']]],
            ['selectedFields' => ['alternativeNames'], 'nameLanguage' => 'cs'],
            ['selectedFields' => ['nameLanguage'], 'nameLanguage' => 'not a tag'],
            ] as $body
        ) {
            try {
                $controller(self::CHANGE_REQUEST_ID, $this->jsonRequest($body));
                self::fail('Expected a 400 for ' . json_encode($body));
            } catch (BadRequestHttpException) {
            }
        }
    }

    public function testTheFieldsToApplyAreNeverImplied(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(), self::REVIEWER_ID);

        foreach ([[], ['selectedFields' => 'name'], ['selectedFields' => ['brand']]] as $body) {
            try {
                $controller(self::CHANGE_REQUEST_ID, $this->jsonRequest($body));
                self::fail('Expected a 400 for ' . json_encode($body));
            } catch (BadRequestHttpException) {
            }
        }
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(BadRequestHttpException::class);

        (new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(), ''))(self::CHANGE_REQUEST_ID, $this->jsonRequest(['selectedFields' => []]));
    }

    public function testAnUnknownChangeRequestIsNotFound(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(PuzzleChangeRequestNotFound::class);

        (new ApprovePuzzleChangeRequestController($bus, $this->changeRequests(null), self::REVIEWER_ID))(
            self::CHANGE_REQUEST_ID,
            $this->jsonRequest(['selectedFields' => []]),
        );
    }

    public function testAPuzzleChangedSinceItsVersionWasReadAnswersConflictAndAppliesNothing(): void
    {
        $response = $this->realController()(
            PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            $this->jsonRequest(['selectedFields' => ['ean'], 'recordVersion' => '0000000000000000']),
        );

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertStringContainsString('changed after its recordVersion was read', (string) $response->getContent());

        $changeRequest = self::getContainer()->get(PuzzleChangeRequestRepository::class)->get(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        self::assertSame(PuzzleReportStatus::Pending, $changeRequest->status);
    }

    public function testThePuzzleAsItWasReadIsApproved(): void
    {
        self::bootKernel();
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);

        $response = $this->realController()(
            PuzzleReportFixture::CHANGE_REQUEST_PENDING,
            $this->jsonRequest(['selectedFields' => ['ean'], 'recordVersion' => PuzzleRecordVersion::ofPuzzle($puzzle)]),
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('1234567890123', self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01)->ean);
    }

    private function realController(): ApprovePuzzleChangeRequestController
    {
        self::bootKernel();
        $container = self::getContainer();

        return new ApprovePuzzleChangeRequestController(
            $container->get(MessageBusInterface::class),
            $container->get(GetPuzzleChangeRequests::class),
            PlayerFixture::PLAYER_ADMIN,
        );
    }

    private function changeRequests(null|string $puzzleId = self::PUZZLE_ID): GetPuzzleChangeRequests
    {
        $database = $this->createStub(Connection::class);
        $database->method('fetchOne')->willReturn($puzzleId ?? false);

        return new GetPuzzleChangeRequests($database, $this->createStub(ClockInterface::class));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/puzzle-change-requests/' . self::CHANGE_REQUEST_ID . '/approve',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
