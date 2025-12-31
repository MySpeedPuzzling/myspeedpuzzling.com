<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpFoundation\RequestStack;

readonly final class SentryTransactionNameEnhancer
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(): callable
    {
        return function (Event $event, null|EventHint $hint): Event {
            $transaction = $event->getTransaction();

            if ($transaction === null) {
                return $event;
            }

            // Enhance Live Component transaction names
            if (str_contains($transaction, 'ux_live_component')) {
                $request = $this->requestStack->getCurrentRequest();

                if ($request !== null) {
                    $componentName = $request->attributes->get('_live_component')
                        ?? $request->attributes->get('_component_name');

                    if (is_string($componentName) && $componentName !== '') {
                        $liveAction = $request->attributes->get('_live_action');
                        $liveAction = is_string($liveAction) ? $liveAction : 'render';

                        // Format: "LiveComponent::ComponentName::action"
                        $event->setTransaction(
                            sprintf('LiveComponent::%s::%s', $componentName, $liveAction)
                        );
                    }
                }
            }

            return $event;
        };
    }
}
