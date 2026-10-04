<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\ApprovePuzzleChangeRequestController;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApprovePuzzleChangeRequestControllerTest extends TestCase
{
    private const string CHANGE_REQUEST_ID = '01a0db4b-0db4-7207-b080-5ba0e2549720';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

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

        $response = (new ApprovePuzzleChangeRequestController($bus, self::REVIEWER_ID))(
            self::CHANGE_REQUEST_ID,
            $this->jsonRequest(['selectedFields' => [], 'decisionNote' => 'Already fixed by the Edcu → Educa merge']),
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(ApprovePuzzleChangeRequest::class, $dispatched);
        self::assertSame(self::CHANGE_REQUEST_ID, $dispatched->changeRequestId);
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId, 'Reviewer comes from config, never from the request body');
        self::assertSame([], $dispatched->selectedFields);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
        self::assertSame('Already fixed by the Edcu → Educa merge', $dispatched->decisionNote);
    }

    public function testTheFieldsToApplyAreNeverImplied(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $controller = new ApprovePuzzleChangeRequestController($bus, self::REVIEWER_ID);

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

        (new ApprovePuzzleChangeRequestController($bus, ''))(self::CHANGE_REQUEST_ID, $this->jsonRequest(['selectedFields' => []]));
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
