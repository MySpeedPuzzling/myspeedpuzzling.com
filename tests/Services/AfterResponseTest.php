<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use SpeedPuzzling\Web\Services\AfterResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AfterResponseTest extends TestCase
{
    public function testDeferredWorkRunsAtTerminateInOrderAndAFailureStopsNothing(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = (is_string($level) ? $level : '?') . ': ' . $message;
            }
        };
        $afterResponse = new AfterResponse($logger);
        $ran = new \ArrayObject();

        $afterResponse->defer(static function () use ($ran): void {
            $ran[] = 'first';
        });
        $afterResponse->defer(static function (): void {
            throw new \RuntimeException('hub down');
        });
        $afterResponse->defer(static function () use ($ran): void {
            $ran[] = 'third';
        });

        self::assertSame([], $ran->getArrayCopy(), 'nothing runs before the response is sent');

        $afterResponse->onTerminate(new TerminateEvent(self::createStub(HttpKernelInterface::class), new Request(), new Response()));

        self::assertSame(['first', 'third'], $ran->getArrayCopy());
        self::assertSame(['warning: Work deferred after the response failed'], $logger->records);

        // Run once only
        $afterResponse->run();
        self::assertSame(['first', 'third'], $ran->getArrayCopy());
    }

    public function testResetDropsWhatARequestLeft(): void
    {
        $afterResponse = new AfterResponse(new \Psr\Log\NullLogger());
        $ran = new \ArrayObject();
        $afterResponse->defer(static function () use ($ran): void {
            $ran[] = 'ran';
        });

        $afterResponse->reset();
        $afterResponse->run();

        self::assertCount(0, $ran);
    }
}
