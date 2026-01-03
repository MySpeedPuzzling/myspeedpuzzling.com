<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enhances Sentry transaction names for Live Component requests.
 *
 * Uses RESPONSE event to ensure the component name and action are fully resolved
 * after the controller has executed.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: 10)]
readonly final class SentryLiveComponentTransactionListener
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest() === false) {
            return;
        }

        $request = $event->getRequest();

        if ($request->attributes->has('_live_component') === false) {
            return;
        }

        $transaction = $this->hub->getTransaction();

        if ($transaction === null) {
            return;
        }

        $componentName = $request->attributes->get('_component_name')
            ?? $request->attributes->get('_live_component');

        if (!is_string($componentName) || $componentName === '') {
            return;
        }

        $liveAction = $request->attributes->get('_live_action');
        $action = is_string($liveAction) && $liveAction !== '' ? $liveAction : 'render';

        $transaction->setName(
            sprintf('LiveComponent::%s::%s', $componentName, $action)
        );
    }
}
