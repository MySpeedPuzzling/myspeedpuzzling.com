<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\EventSubscriber;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\EventSubscriber\UploadFailureFlashSubscriber;
use SpeedPuzzling\Web\Services\UploadFailureCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class UploadFailureFlashSubscriberTest extends TestCase
{
    private UploadFailureCollector $collector;
    private UploadFailureFlashSubscriber $subscriber;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->collector = new UploadFailureCollector();
        $this->requestStack = new RequestStack();

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->subscriber = new UploadFailureFlashSubscriber(
            $this->collector,
            $translator,
            $this->requestStack,
        );
    }

    public function testWarningFlashAddedOnPostWithFailures(): void
    {
        $this->collector->recordFailure('players/1/photo.jpg');
        [$event, $session] = $this->createEvent('POST', withPreviousSession: true);

        $this->subscriber->onKernelResponse($event);

        self::assertSame(['flashes.photo_upload_deferred'], $session->getFlashBag()->get('warning'));
    }

    public function testNoFlashOnSafeMethod(): void
    {
        // A GET regenerating a cached image must not park a warning flash
        $this->collector->recordFailure('players/1/results/x.png');
        [$event, $session] = $this->createEvent('GET', withPreviousSession: true);

        $this->subscriber->onKernelResponse($event);

        self::assertSame([], $session->getFlashBag()->get('warning'));
    }

    public function testNoFlashWithoutFailures(): void
    {
        [$event, $session] = $this->createEvent('POST', withPreviousSession: true);

        $this->subscriber->onKernelResponse($event);

        self::assertSame([], $session->getFlashBag()->get('warning'));
    }

    public function testNoFlashWithoutPreviousSession(): void
    {
        // Stateless requests (API) must never have a session started for them
        $this->collector->recordFailure('players/1/photo.jpg');
        [$event, $session] = $this->createEvent('POST', withPreviousSession: false);

        $this->subscriber->onKernelResponse($event);

        self::assertSame([], $session->getFlashBag()->get('warning'));
    }

    /**
     * @return array{0: ResponseEvent, 1: Session}
     */
    private function createEvent(string $method, bool $withPreviousSession): array
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/add-time', $method);
        $request->setSession($session);

        if ($withPreviousSession) {
            $request->cookies->set($session->getName(), 'session-id');
        }

        $this->requestStack->push($request);

        $event = new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response(),
        );

        return [$event, $session];
    }
}
