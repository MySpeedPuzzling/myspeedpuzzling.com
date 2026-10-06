<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SpeedPuzzling\Web\EventSubscriber\InternalApiErrorResponseSubscriber;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

final class InternalApiErrorResponseSubscriberTest extends TestCase
{
    public function testAnHttpRefusalBecomesJson(): void
    {
        $event = $this->handle('/internal-api/rounds/x', new ConflictHttpException('The round has 3 result(s).'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(409, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString('{"error":"The round has 3 result(s)."}', (string) $response->getContent());
    }

    public function testInvalidFieldsAreListed(): void
    {
        $event = $this->handle('/internal-api/competitions', new InternalApiInvalidInput(['name' => 'is required.']));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('"errors":{"name":"is required."}', (string) $response->getContent());
    }

    public function testAnEncodedPathIsTheInternalApiToo(): void
    {
        self::assertNotNull($this->handle('/internal%2Dapi/competitions', new ConflictHttpException('Taken.'))->getResponse());
    }

    public function testABugIsLeftToSymfony(): void
    {
        self::assertNull($this->handle('/internal-api/competitions', new RuntimeException('A new entity was found'))->getResponse());
        self::assertNull($this->handle('/internal-api/competitions', new HttpException(500, 'Upstream down'))->getResponse());
    }

    public function testOtherPathsAreLeftToSymfony(): void
    {
        self::assertNull($this->handle('/en/events/x', new ConflictHttpException('Taken.'))->getResponse());
        self::assertNull($this->handle('/api/v1/competitions', new ConflictHttpException('Taken.'))->getResponse());
    }

    private function handle(string $path, Throwable $exception): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        (new InternalApiErrorResponseSubscriber())->onKernelException($event);

        return $event;
    }
}
