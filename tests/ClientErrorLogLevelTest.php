<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * A request the client got wrong is answered with its 4xx and logged at info, so it never becomes
 * a Sentry issue (WEB-8F "Could not decode request body", WEB-BH, WEB-D0). Other failures keep
 * Symfony's levels and stay visible.
 */
final class ClientErrorLogLevelTest extends KernelTestCase
{
    #[DataProvider('uncaughtExceptions')]
    public function testUncaughtExceptionIsLoggedAt(Throwable $exception, string $expectedLevel): void
    {
        self::bootKernel();

        /** @var ErrorListener $productionListener */
        $productionListener = self::getContainer()->get('exception_listener');

        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->levels[] = is_string($level) ? $level : 'unexpected';
            }
        };

        $listener = new ErrorListener(null, $logger, false, $this->exceptionsMapping($productionListener));
        $listener->logKernelException(new ExceptionEvent(
            self::$kernel ?? throw new RuntimeException('Kernel not booted'),
            Request::create('/internal-api/puzzle-change-requests/x/reject', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        ));

        self::assertSame([$expectedLevel], $logger->levels);
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function uncaughtExceptions(): iterable
    {
        yield '400 bad request' => [new BadRequestHttpException('"rejectionReason" is required.'), LogLevel::INFO];
        yield '406 not acceptable' => [new NotAcceptableHttpException('Requested format "application/json" is not supported.'), LogLevel::INFO];
        yield '415 unsupported media type' => [new UnsupportedMediaTypeHttpException(), LogLevel::INFO];
        yield '409 conflict stays visible' => [new ConflictHttpException('A team with results cannot be deleted.'), LogLevel::ERROR];
        yield 'a bug stays critical' => [new RuntimeException('A new entity was found'), LogLevel::CRITICAL];
    }

    /**
     * @return array<class-string, array{log_level: string|null, status_code: int<100,599>|null, log_channel: string|null}>
     */
    private function exceptionsMapping(ErrorListener $listener): array
    {
        /** @var array<class-string, array{log_level: string|null, status_code: int<100,599>|null, log_channel: string|null}> $mapping */
        $mapping = (new \ReflectionProperty(ErrorListener::class, 'exceptionsMapping'))->getValue($listener);

        return $mapping;
    }
}
