<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\RejectPuzzleChangeRequestController;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RejectPuzzleChangeRequestControllerTest extends TestCase
{
    private const string CHANGE_REQUEST_ID = '01a0edf2-532c-7084-b181-a988636d6f85';

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

        $controller = new RejectPuzzleChangeRequestController($bus, self::REVIEWER_ID);

        $response = $controller(self::CHANGE_REQUEST_ID, $this->jsonRequest([
            'rejectionReason' => 'The proposed EAN is not valid EAN barcode',
        ]));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(RejectPuzzleChangeRequest::class, $dispatched);
        self::assertSame(self::CHANGE_REQUEST_ID, $dispatched->changeRequestId);
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId);
        self::assertSame('The proposed EAN is not valid EAN barcode', $dispatched->rejectionReason);
    }

    public function testRejectsBlankReason(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new RejectPuzzleChangeRequestController($bus, self::REVIEWER_ID);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('"rejectionReason" is required.');

        // The reason is shown to the player who proposed the change, so it may never be empty
        $controller(self::CHANGE_REQUEST_ID, $this->jsonRequest(['rejectionReason' => '  ']));
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new RejectPuzzleChangeRequestController($bus, '');

        $this->expectException(BadRequestHttpException::class);

        $controller(self::CHANGE_REQUEST_ID, $this->jsonRequest(['rejectionReason' => 'The proposed EAN is not valid EAN barcode']));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/puzzle-change-requests/' . self::CHANGE_REQUEST_ID . '/reject',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
