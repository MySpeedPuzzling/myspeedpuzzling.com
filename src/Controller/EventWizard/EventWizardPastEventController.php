<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\FormData\PastEventFormData;
use SpeedPuzzling\Web\FormType\PastEventFormType;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Message\AddEdition;
use SpeedPuzzling\Web\Message\AddOrganization;
use SpeedPuzzling\Web\Message\CreatePastEvent;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Query\GetPastEventMatches;
use SpeedPuzzling\Web\Query\GetPastEventSeriesChoices;
use SpeedPuzzling\Web\Results\CompetitionPermissions;
use SpeedPuzzling\Web\Results\PastEventMatch;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Services\Organizations\OrganizationSelectChoices;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The backfill path's first step (docs/features/competitions-management/creation-wizard.md "Backfill"): "An event that
 * already happened" - one quick form (name, day, where; its series or organization only when the player says so).
 *
 * "Is it one of these?" before anything is created: a valid submit first looks for the event among the existing ones
 * (GetPastEventMatches). Found → the same page again (422) with the cards above the kept form, each with the one action
 * that fits the player's rights:
 * - an event they may edit → its puzzles step; one they may not → straight to "Link my results" for it;
 * - a series they may add dates to → "Add this date to it" (this submit again, into the series); one they may not →
 *   "Link my results" to the series (a series pick - its date is found by itself);
 * - an organization they are on the team of → "Add it under this organization"; else only its page.
 * "None of these - create it" (`create_anyway`) creates it: CreatePastEvent, submitted for approval like the classic
 * form (never a draft), then its puzzles step.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardPastEventController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSteps $eventWizardSteps,
        private readonly OrganizationSelectChoices $organizationSelectChoices,
        private readonly GetCompetitionPermissions $getCompetitionPermissions,
        private readonly GetPastEventSeriesChoices $getPastEventSeriesChoices,
        private readonly GetPastEventMatches $getPastEventMatches,
        private readonly MessageBusInterface $messageBus,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/past',
        name: 'event_wizard_past',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->access->player();
        $isAdmin = $this->isGranted(AdminAccessVoter::ADMIN_ACCESS);
        $permissions = $this->getCompetitionPermissions->forPlayer($player->playerId);

        $organizationChoices = [];
        foreach ($this->organizationSelectChoices->forPlayer($player->playerId, false) as $choice) {
            $organizationChoices[$choice->id] = $choice->name;
        }

        $seriesChoices = $this->getPastEventSeriesChoices->of($permissions->editableSeriesIds());

        $data = new PastEventFormData();
        $data->locationCountryCode = $player->countryCode?->name;
        $preselected = $this->chosen($request->query->getString('organization'), $organizationChoices);

        if ($preselected !== null) {
            $data->partOf = true;
            $data->organization = $preselected;
        }

        $form = $this->createForm(PastEventFormType::class, $data, [
            'action' => $this->generateUrl('event_wizard_past'),
            'series_choices' => $seriesChoices,
            'organization_choices' => $organizationChoices,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $intoSeries = $this->chosen($request->request->getString('into_series'), $seriesChoices);
            $intoOrganization = $this->chosen($request->request->getString('into_organization'), $organizationChoices);
            $decided = $request->request->getBoolean('create_anyway') || $intoSeries !== null || $intoOrganization !== null;

            if ($decided === false) {
                $matches = $this->getPastEventMatches->find(
                    name: (string) $data->name,
                    date: $data->date,
                    countryCode: $data->isOnline ? null : $data->locationCountryCode,
                    isOnline: $data->isOnline,
                    ownCompetitionIds: $isAdmin ? [] : $permissions->editableCompetitionIds(),
                    ownSeriesIds: $isAdmin ? [] : $permissions->editableSeriesIds(),
                    ownOrganizationIds: array_keys($organizationChoices),
                );

                if ($matches !== []) {
                    return $this->page($form, $this->cards($matches, $permissions, $isAdmin, $seriesChoices, $organizationChoices, $data->date), Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            $message = $this->message($data, $player, $intoSeries, $intoOrganization);
            $this->messageBus->dispatch($message);

            return $this->redirectToRoute('event_wizard_past_puzzles', ['competitionId' => $message->eventId()], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, [], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * @param array<string, string> $choices
     */
    private function chosen(string $id, array $choices): null|string
    {
        $id = strtolower(trim($id));

        return $id !== '' && isset($choices[$id]) ? $id : null;
    }

    private function message(PastEventFormData $data, PlayerProfile $player, null|string $intoSeries, null|string $intoOrganization): CreatePastEvent
    {
        $name = trim((string) $data->name);
        $date = $data->date ?? $this->clock->now();
        $location = $data->isOnline ? null : (trim((string) $data->location) !== '' ? trim((string) $data->location) : null);
        $country = $data->locationCountryCode !== null && $data->locationCountryCode !== '' ? $data->locationCountryCode : null;

        $organization = null;
        $organizationId = $intoOrganization ?? $data->existingOrganizationId();

        if ($intoOrganization === null && $intoSeries === null && $data->wantsNewOrganization()) {
            $organization = new AddOrganization(
                organizationId: Uuid::uuid7(),
                playerId: $player->playerId,
                name: trim((string) $data->newOrganizationName),
                shortName: null,
                about: null,
                website: null,
                socialLinks: [],
                countryCode: $country,
                region: null,
                kind: null,
                logo: null,
                maintainerIds: [],
            );
            $organizationId = $organization->organizationId->toString();
        }

        $seriesId = $intoSeries ?? ($intoOrganization === null ? $data->existingSeriesId() : null);
        $series = null;

        if ($seriesId === null && $intoOrganization === null && $data->wantsNewSeries()) {
            $series = new AddCompetitionSeries(
                seriesId: Uuid::uuid7(),
                playerId: $player->playerId,
                name: trim((string) $data->newSeriesName),
                shortcut: null,
                description: null,
                link: null,
                isOnline: $data->isOnline,
                location: $location,
                locationCountryCode: $country,
                logo: null,
                maintainerIds: [],
                organizationId: $organizationId,
            );
            $seriesId = $series->seriesId->toString();
        }

        if ($seriesId !== null) {
            return new CreatePastEvent(
                organization: $organization,
                series: $series,
                competition: null,
                edition: new AddEdition(
                    competitionId: Uuid::uuid7(),
                    seriesId: $seriesId,
                    name: $name,
                    dateFrom: $date,
                    dateTo: $date,
                    registrationLink: null,
                    resultsLink: null,
                ),
            );
        }

        return new CreatePastEvent(
            organization: $organization,
            series: null,
            competition: new AddCompetition(
                competitionId: Uuid::uuid7(),
                playerId: $player->playerId,
                name: $name,
                shortcut: null,
                description: null,
                link: null,
                registrationLink: null,
                resultsLink: null,
                location: $location,
                locationCountryCode: $country,
                dateFrom: $date,
                dateTo: $date,
                isOnline: $data->isOnline,
                logo: null,
                maintainerIds: [],
                organizationId: $organizationId,
            ),
            edition: null,
        );
    }

    /**
     * Each match with the one action the player's rights give it
     *
     * @param list<PastEventMatch> $matches
     * @param array<string, string> $seriesChoices
     * @param array<string, string> $organizationChoices
     * @return list<array{match: PastEventMatch, action: string, url: null|string}>
     */
    private function cards(array $matches, CompetitionPermissions $permissions, bool $isAdmin, array $seriesChoices, array $organizationChoices, null|DateTimeImmutable $date): array
    {
        $cards = [];

        foreach ($matches as $match) {
            [$action, $url] = match ($match->kind) {
                PastEventMatch::EVENT => $isAdmin || $permissions->canEditCompetition($match->id)
                    ? ['puzzles', $this->generateUrl('event_wizard_past_puzzles', ['competitionId' => $match->id])]
                    : ['link', $this->generateUrl('event_wizard_link_results', ['competitionId' => $match->id])],
                PastEventMatch::SERIES => isset($seriesChoices[$match->id])
                    ? ['add_date', null]
                    : ['link_series', $this->generateUrl('event_wizard_series_link_results', array_filter(['seriesId' => $match->id, 'date' => $date?->format('Y-m-d')]))],
                default => isset($organizationChoices[$match->id])
                    ? ['organization', null]
                    : ['organization_page', $this->generateUrl('organization_detail', ['slug' => $match->slug])],
            };

            $cards[] = ['match' => $match, 'action' => $action, 'url' => $url];
        }

        return $cards;
    }

    /**
     * @param FormInterface<PastEventFormData> $form
     * @param list<array{match: PastEventMatch, action: string, url: null|string}> $cards
     */
    private function page(FormInterface $form, array $cards, int $status): Response
    {
        return $this->render('event_wizard/past_event.html.twig', [
            'form' => $form,
            'cards' => $cards,
            'progress' => $this->eventWizardSteps->forBackfill(null, EventWizardStep::PastEvent),
            'step' => EventWizardStep::PastEvent,
        ], new Response(status: $status));
    }
}
