<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SpeedPuzzling\Web\Exceptions\LentPuzzleNotFound;
use SpeedPuzzling\Web\Services\MessengerMiddleware\UnwrapHttpExceptionMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Throwable;

/**
 * The two paths that matter:
 *  - an HTTP-flavoured domain exception must reach the caller AS ITSELF, so it
 *    renders with its own status (404/403) and Sentry ignores it on purpose;
 *  - anything else must keep its HandlerFailedException, so it stays a 500 and
 *    stays VISIBLE in Sentry (the regression that hid production 500s for a week).
 */
final class UnwrapHttpExceptionMiddlewareTest extends TestCase
{
    public function testUnwrapsNotFoundStyleDomainException(): void
    {
        $domainException = new LentPuzzleNotFound();

        try {
            $this->handleThrowing($domainException);
            self::fail('Expected an exception to be thrown.');
        } catch (Throwable $caught) {
            self::assertSame($domainException, $caught, 'The domain exception must surface unwrapped.');
        }
    }

    public function testUnwrapsAccessDeniedSoTheSecurityListenerCanSeeIt(): void
    {
        // Wrapped, the security listener never sees it and it becomes a 500 that
        // Sentry also discards (AccessDeniedException is in ignore_exceptions).
        // Nothing throws it from a handler today; covered so it cannot regress in.
        $domainException = new AccessDeniedException();

        try {
            $this->handleThrowing($domainException);
            self::fail('Expected an exception to be thrown.');
        } catch (Throwable $caught) {
            self::assertSame($domainException, $caught);
        }
    }

    public function testKeepsWrapperForGenericFailuresSoTheyStayVisible(): void
    {
        $bug = new RuntimeException('the kind of bug we must never hide');

        try {
            $this->handleThrowing($bug);
            self::fail('Expected an exception to be thrown.');
        } catch (Throwable $caught) {
            self::assertInstanceOf(HandlerFailedException::class, $caught);
            self::assertSame($bug, $caught->getPrevious());
        }
    }

    public function testUnwrapsThroughNestedHandlerFailures(): void
    {
        // A handler dispatching another message (sync events do this) nests the
        // wrapper one level deeper.
        $domainException = new LentPuzzleNotFound();
        $envelope = new Envelope(new \stdClass());
        $inner = new HandlerFailedException($envelope, [$domainException]);

        try {
            $this->handleThrowing($inner);
            self::fail('Expected an exception to be thrown.');
        } catch (Throwable $caught) {
            self::assertSame($domainException, $caught);
        }
    }

    public function testPassesSuccessfulEnvelopesThrough(): void
    {
        $middleware = new UnwrapHttpExceptionMiddleware();
        $envelope = new Envelope(new \stdClass());

        self::assertSame($envelope, $middleware->handle($envelope, $this->stackReturning($envelope)));
    }

    /**
     * Runs the middleware over a stack whose handler fails with $failure,
     * wrapped exactly as Messenger would wrap it.
     */
    private function handleThrowing(Throwable $failure): Envelope
    {
        $middleware = new UnwrapHttpExceptionMiddleware();
        $envelope = new Envelope(new \stdClass());

        return $middleware->handle($envelope, $this->stackThrowing(
            new HandlerFailedException($envelope, [$failure]),
        ));
    }

    private function stackThrowing(Throwable $exception): StackInterface
    {
        return new class ($exception) implements StackInterface, MiddlewareInterface {
            public function __construct(private Throwable $exception)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this;
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->exception;
            }
        };
    }

    private function stackReturning(Envelope $result): StackInterface
    {
        return new class ($result) implements StackInterface, MiddlewareInterface {
            public function __construct(private Envelope $result)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this;
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $this->result;
            }
        };
    }
}
