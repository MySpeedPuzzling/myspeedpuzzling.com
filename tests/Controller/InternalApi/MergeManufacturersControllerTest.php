<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Controller\InternalApi\MergeManufacturersController;
use SpeedPuzzling\Web\Message\MergeManufacturers;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MergeManufacturersControllerTest extends TestCase
{
    private const string SURVIVOR_ID = '018d0002-0000-0000-0000-000000000001';

    private const string DUPLICATE_ID = '01977f76-0d5b-7378-baa6-ebf37a04f360';

    private const string REVIEWER_ID = '018b9c2b-7437-7235-b231-162cdf07d234';

    public function testDispatchesTheMergeWithDecisionMetadata(): void
    {
        $dispatched = null;
        $controller = new MergeManufacturersController($this->messageBusCapturing($dispatched), self::REVIEWER_ID);

        $response = $controller(self::SURVIVOR_ID, $this->jsonRequest([
            'mergedManufacturerIds' => [strtoupper(self::DUPLICATE_ID), self::DUPLICATE_ID],
            'name' => 'PusselParet',
            'decisionConfidence' => 'high',
            'decisionNote' => 'Identical name, same EAN on every puzzle.',
        ]));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertInstanceOf(MergeManufacturers::class, $dispatched);
        self::assertSame(self::SURVIVOR_ID, $dispatched->survivorManufacturerId);
        self::assertSame([self::DUPLICATE_ID], $dispatched->mergedManufacturerIds, 'Ids are lowercased and de-duplicated');
        self::assertSame(self::REVIEWER_ID, $dispatched->reviewerId, 'Reviewer comes from config, never from the request body');
        self::assertSame('PusselParet', $dispatched->survivorName);
        self::assertSame(MergeDecisionSource::InternalApi, $dispatched->decisionSource);
        self::assertSame(MergeDecisionConfidence::High, $dispatched->decisionConfidence);
    }

    public function testRejectsAMissingOrMalformedList(): void
    {
        $controller = new MergeManufacturersController($this->messageBusExpectingNoDispatch(), self::REVIEWER_ID);

        foreach ([[], ['mergedManufacturerIds' => []], ['mergedManufacturerIds' => ['not-an-id']], ['mergedManufacturerIds' => self::DUPLICATE_ID]] as $body) {
            try {
                $controller(self::SURVIVOR_ID, $this->jsonRequest($body));
                self::fail('Expected a 400 for ' . json_encode($body));
            } catch (BadRequestHttpException) {
            }
        }
    }

    public function testRefusesToActWhenNoReviewerIsConfigured(): void
    {
        $controller = new MergeManufacturersController($this->messageBusExpectingNoDispatch(), '');

        $this->expectException(BadRequestHttpException::class);

        $controller(self::SURVIVOR_ID, $this->jsonRequest(['mergedManufacturerIds' => [self::DUPLICATE_ID]]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return Request::create(
            '/internal-api/manufacturers/' . self::SURVIVOR_ID . '/merge',
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
