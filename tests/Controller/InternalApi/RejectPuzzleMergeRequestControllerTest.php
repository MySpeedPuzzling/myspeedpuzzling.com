<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\RejectPuzzleMergeRequestController;
use SpeedPuzzling\Web\Message\RejectPuzzleMergeRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RejectPuzzleMergeRequestControllerTest extends TestCase
{
    private const string MERGE_REQUEST_ID = '019e32be-0000-7000-8000-000000000001';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    public function testDispatchesRejectionWithReason(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $controller = new RejectPuzzleMergeRequestController($bus, self::REVIEWER_ID);

        $response = $controller(self::MERGE_REQUEST_ID, $this->jsonRequest([
            'rejectionReason' => 'Different products: 500 and 900 pieces.',
        ]));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(RejectPuzzleMergeRequest::class, $dispatched);
        self::assertSame(self::MERGE_REQUEST_ID, $dispatched->mergeRequestId);
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId);
        self::assertSame('Different products: 500 and 900 pieces.', $dispatched->rejectionReason);
    }

    public function testRejectsBlankReason(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new RejectPuzzleMergeRequestController($bus, self::REVIEWER_ID);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"rejectionReason" is required.');

        // The reason is shown to the player who reported the duplicate, so it may never be empty
        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest(['rejectionReason' => '  ']));
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new RejectPuzzleMergeRequestController($bus, '');

        $this->expectException(BadRequestHttpException::class);

        $controller(self::MERGE_REQUEST_ID, $this->jsonRequest(['rejectionReason' => 'Not a duplicate.']));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/puzzle-merge-requests/' . self::MERGE_REQUEST_ID . '/reject',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
