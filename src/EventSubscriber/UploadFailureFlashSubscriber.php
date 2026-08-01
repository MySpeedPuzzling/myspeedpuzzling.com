<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Services\UploadFailureCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * When an upload fell back to the local spool during this request, tell the
 * user their data is safe and the file will be uploaded automatically later.
 * No controller changes needed - any flow that writes files is covered.
 */
final readonly class UploadFailureFlashSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UploadFailureCollector $collector,
        private TranslatorInterface $translator,
        private RequestStack $requestStack,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->collector->hasFailures()) {
            return;
        }

        $request = $event->getRequest();

        // Only user-initiated mutations flash - a GET regenerating a cached
        // image must not park a warning on someone's next page view
        if ($request->isMethodSafe()) {
            return;
        }

        // Never start a session here (stateless API requests must stay
        // cookie-free); upload flows always come from logged-in users
        if (!$request->hasPreviousSession()) {
            return;
        }

        try {
            $session = $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return;
        }

        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add(
            'warning',
            $this->translator->trans('flashes.photo_upload_deferred'),
        );
    }
}
