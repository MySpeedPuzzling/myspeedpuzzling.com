<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\RevertPuzzleMergeRequestController;
use SpeedPuzzling\Web\Message\RevertPuzzleMergeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RevertPuzzleMergeRequestControllerTest extends TestCase
{
    private const string MERGE_REQUEST_ID = '019e32be-0000-7000-8000-000000000001';

    private const string SURVIVOR_ID = '019e32be-0000-7000-8000-000000000002';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    public function testDispatchesTheRevertCreditedToTheReviewer(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $controller = new RevertPuzzleMergeRequestController($bus, self::REVIEWER_ID);

        $response = $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'survivorPuzzleId' => self::SURVIVOR_ID,
            'note' => ' Two different boxes ',
        ]));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(RevertPuzzleMergeRequest::class, $dispatched);
        self::assertSame(self::MERGE_REQUEST_ID, $dispatched->mergeRequestId);
        self::assertSame(self::SURVIVOR_ID, $dispatched->survivorPuzzleId);
        self::assertSame(self::REVIEWER_ID, $dispatched->adminId);
        self::assertSame('Two different boxes', $dispatched->note);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
    }

    public function testTheSurvivorIsRequired(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new RevertPuzzleMergeRequestController($bus, self::REVIEWER_ID);

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([]));
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new RevertPuzzleMergeRequestController($bus, '');

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest(['survivorPuzzleId' => self::SURVIVOR_ID]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/puzzle-merge-requests/' . self::MERGE_REQUEST_ID . '/revert',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
