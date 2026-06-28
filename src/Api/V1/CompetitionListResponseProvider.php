<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<CompetitionListResponse>
 */
final readonly class CompetitionListResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetCompetitionEvents $getCompetitionEvents,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompetitionListResponse
    {
        $request = $this->requestStack->getCurrentRequest();

        $status = $request?->query->getString('status', 'all') ?? 'all';
        if (in_array($status, ['all', 'live', 'upcoming', 'past'], true) === false) {
            $status = 'all';
        }

        $onlineOnly = $request?->query->getBoolean('online', false) ?? false;

        $country = $request?->query->getString('country', '') ?? '';
        $country = $country !== '' ? strtolower($country) : null;

        $events = $this->getCompetitionEvents->search($status, $onlineOnly, $country);

        $competitions = array_map(
            static fn (CompetitionEvent $event): CompetitionListItemResponse => new CompetitionListItemResponse(
                id: $event->id,
                name: $event->name,
                shortcut: $event->shortcut,
                slug: $event->slug,
                logo: $event->logo,
                location: $event->location,
                country_code: $event->locationCountryCode?->name,
                is_online: $event->isOnline,
                date_from: $event->dateFrom?->format('c'),
                date_to: $event->dateTo?->format('c'),
                status: $event->eventStatus,
                link: $event->link,
                registration_link: $event->registrationLink,
                results_link: $event->resultsLink,
            ),
            $events,
        );

        return new CompetitionListResponse(
            count: count($competitions),
            competitions: $competitions,
        );
    }
}
