<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\SubmitPuzzleMergeRequestController;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitPuzzleMergeRequestControllerTest extends TestCase
{
    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    private const string PUZZLE_A = '01977f76-0d5a-73a0-953d-7d655a5c6dc3';

    private const string PUZZLE_B = '0197acfd-1132-7007-9610-c6ce24443e69';

    public function testFilesTheReportAsTheReviewerAndAnswersItsId(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $response = (new SubmitPuzzleMergeRequestController($bus, self::REVIEWER_ID))(
            $this->jsonRequest(['puzzleIds' => [self::PUZZLE_A, self::PUZZLE_B, self::PUZZLE_A]]),
        );

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertInstanceOf(SubmitPuzzleMergeRequest::class, $dispatched);
        self::assertSame(self::REVIEWER_ID, $dispatched->reporterId);
        self::assertSame(self::PUZZLE_A, $dispatched->sourcePuzzleId);
        self::assertSame([self::PUZZLE_B], $dispatched->duplicatePuzzleIds);

        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame($dispatched->mergeRequestId, $body['mergeRequestId'] ?? null);
    }

    public function testNeedsTwoDistinctPuzzles(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(BadRequestHttpException::class);

        (new SubmitPuzzleMergeRequestController($bus, self::REVIEWER_ID))(
            $this->jsonRequest(['puzzleIds' => [self::PUZZLE_A, self::PUZZLE_A]]),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create('/internal-api/puzzle-merge-requests', 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
    }
}
