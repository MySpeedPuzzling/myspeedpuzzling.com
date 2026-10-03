<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\ApproveManufacturerController;
use SpeedPuzzling\Web\Message\ApproveManufacturer;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApproveManufacturerControllerTest extends TestCase
{
    private const string MANUFACTURER_ID = '018d0002-0000-0000-0000-000000000003';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    public function testDispatchesTheApproval(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        $response = (new ApproveManufacturerController($bus, self::REVIEWER_ID))(self::MANUFACTURER_ID, $this->jsonRequest([
            'name' => 'Pusselbolaget',
            'decisionNote' => '   ',
        ]));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(ApproveManufacturer::class, $dispatched);
        self::assertSame(self::MANUFACTURER_ID, $dispatched->manufacturerId);
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId);
        self::assertSame('Pusselbolaget', $dispatched->name);
        self::assertNull($dispatched->decisionNote);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
    }

    public function testAnEmptyBodyApprovesUnderTheCurrentName(): void
    {
        $dispatched = null;

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

                return new Envelope($message);
            },
        );

        (new ApproveManufacturerController($bus, self::REVIEWER_ID))(
            self::MANUFACTURER_ID,
            Request::create('/internal-api/manufacturers/' . self::MANUFACTURER_ID . '/approve', 'POST'),
        );

        self::assertInstanceOf(ApproveManufacturer::class, $dispatched);
        self::assertNull($dispatched->name);
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $this->expectException(BadRequestHttpException::class);

        (new ApproveManufacturerController($bus, ''))(self::MANUFACTURER_ID, $this->jsonRequest([]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/manufacturers/' . self::MANUFACTURER_ID . '/approve',
            'POST',
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
