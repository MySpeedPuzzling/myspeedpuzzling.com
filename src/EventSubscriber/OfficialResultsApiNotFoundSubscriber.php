<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\EventSubscriber;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The official results JSON endpoints (routes `official_results_*`, docs/features/competitions-management/official-results.md)
 * and the participants spreadsheet's (routes `participants_sheet_*` - the JSON ones; the page itself, `participants_sheet`,
 * 404s like any page) answer a round or an event that is unknown or was deleted with their own JSON 404 - `{"error": "round_not_found" |
 * "competition_not_found", "message": "…"}` - never Symfony's HTML error page: the pages' client
 * (assets/official_results_api.js) reads a 4xx without our JSON as "the server is busy, retry later", so a deleted
 * round would be retried forever instead of the page saying it is gone.
 *
 * Runs after the listeners that log the exception (priority 0), before Symfony renders the error page (-128) - the
 * same place as InternalApiErrorResponseSubscriber. Every other exception is left to Symfony.
 */
final readonly class OfficialResultsApiNotFoundSubscriber implements EventSubscriberInterface
{
    private const array ROUTE_PREFIXES = ['official_results_', 'participants_sheet_'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');

        if (!is_string($route) || !self::isJsonEndpoint($route)) {
            return;
        }

        $error = match (true) {
            $event->getThrowable() instanceof CompetitionRoundNotFound => 'round_not_found',
            $event->getThrowable() instanceof CompetitionNotFound => 'competition_not_found',
            default => null,
        };

        if ($error === null) {
            return;
        }

        $event->setResponse(OfficialResultsApi::error($error, JsonResponse::HTTP_NOT_FOUND, [
            'message' => $this->translator->trans('official_results.reason.' . $error),
        ]));
    }

    private static function isJsonEndpoint(string $route): bool
    {
        foreach (self::ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
