<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\ApprovePuzzleMergeRequestController;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleMergeRequestControllerTest extends TestCase
{
    private const string MERGE_REQUEST_ID = '019e281a-6b16-7324-8265-0f06673a49cb';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    private const string SURVIVOR_ID = '018eb4c9-d751-70db-ab5d-0b8a4e55d2a7';

    public function testDispatchesApprovalWithDecisionMetadata(): void
    {
        $dispatched = null;

        $controller = new ApprovePuzzleMergeRequestController(
            $this->messageBusCapturing($dispatched),
            self::REVIEWER_ID,
        );

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
        self::assertSame('850006234257', $dispatched->mergedEan);
        self::assertSame(500, $dispatched->mergedPiecesCount);
        self::assertNull($dispatched->mergedManufacturerId);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
        self::assertSame(MergeDecisionConfidence::High, $dispatched->decisionConfidence);
        self::assertSame('Identical artwork and piece count.', $dispatched->decisionNote);
    }

    public function testBlankOptionalStringsBecomeNull(): void
    {
        $dispatched = null;

        $controller = new ApprovePuzzleMergeRequestController(
            $this->messageBusCapturing($dispatched),
            self::REVIEWER_ID,
        );

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedEan' => '   ',
            'mergedPiecesCount' => 500,
        ]));

        self::assertInstanceOf(ApprovePuzzleMergeRequest::class, $dispatched);
        // A blank EAN must not overwrite what the survivor already has
        self::assertNull($dispatched->mergedEan);
        self::assertNull($dispatched->decisionConfidence);
        self::assertNull($dispatched->decisionNote);
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

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $controller = new ApprovePuzzleMergeRequestController(
            $this->messageBusExpectingNoDispatch(),
            '',
        );

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'mergedName' => 'Some Puzzle',
            'mergedPiecesCount' => 500,
        ]));
    }

    private function controllerWithNeverDispatchingBus(): ApprovePuzzleMergeRequestController
    {
        return new ApprovePuzzleMergeRequestController(
            $this->messageBusExpectingNoDispatch(),
            self::REVIEWER_ID,
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
