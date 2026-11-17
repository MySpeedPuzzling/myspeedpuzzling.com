<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use SpeedPuzzling\Web\Query\GetEventsToCopy;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Services\Organizations\OrganizationSelectChoices;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardEntryChoice;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardPath;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStructureTemplate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The event creation wizard's entry, "What are you organizing?" (W-creation-wizard-research.md §3.1) - where "Add event"
 * leads while the wizard is available (AddCompetitionController). The answer comes back to this page as `?kind=` (a GET
 * form - nothing is saved here) and is sent on by target(), the one place that knows where each answer goes. The
 * organization picker is here, only for a player on an organization's team (preselected from `?organization=`, kept
 * on every link); "Copy one of your events" lists the player's 5 most recent events with a link to their Duplicate
 * form (W4: GET `duplicate_competition` asks the new date and name).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardEntryController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly OrganizationSelectChoices $organizationSelectChoices,
        private readonly GetEventsToCopy $getEventsToCopy,
        private readonly EventWizardSteps $eventWizardSteps,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event',
        name: 'event_wizard',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->access->player();

        $organizationChoices = $this->organizationSelectChoices->forPlayer($player->playerId, $this->isGranted(AdminAccessVoter::ADMIN_ACCESS));
        $organizationId = OrganizationSelectChoices::pick($organizationChoices, $request->query->getString('organization'));

        $choice = EventWizardEntryChoice::tryFrom($request->query->getString('kind'));

        if ($choice !== null) {
            return $this->redirect($this->target($choice, $organizationId, EventWizardStructureTemplate::tryFrom($request->query->getString('structure'))));
        }

        return $this->render('event_wizard/entry.html.twig', [
            'choices' => EventWizardEntryChoice::cases(),
            'structure_templates' => EventWizardStructureTemplate::cases(),
            'organization_choices' => $organizationChoices,
            'organization_id' => $organizationId,
            'events_to_copy' => $this->getEventsToCopy->forPlayer($player->playerId),
            'classic_url' => $this->generateUrl('add_competition', array_filter(['classic' => '1', 'organization' => $organizationId])),
        ]);
    }

    /**
     * Where an answer leads: a path with wizard steps opens its first one (the series path its own Basics, W3), any
     * other answer the classic form. Several rounds (W5) carries the follow-up answer ("Do competitors qualify for later
     * rounds?") to its Structure step as `structure`.
     */
    private function target(EventWizardEntryChoice $choice, null|string $organizationId, null|EventWizardStructureTemplate $structure = null): string
    {
        $path = $choice->path();

        if ($path === EventWizardPath::Backfill) {
            return $this->generateUrl('event_wizard_past', array_filter(['organization' => $organizationId]));
        }

        if ($path === EventWizardPath::Series) {
            return $this->generateUrl('event_wizard_series_basics', array_filter(['organization' => $organizationId]));
        }

        if ($path !== null && $this->eventWizardSteps->forNewEvent($path)->steps !== []) {
            return $this->generateUrl('event_wizard_basics', array_filter([
                'path' => $path === EventWizardPath::OneRound ? null : $path->value,
                'structure' => $path === EventWizardPath::SeveralRounds ? ($structure ?? EventWizardStructureTemplate::Qualification)->value : null,
                'organization' => $organizationId,
            ]));
        }

        return $this->generateUrl('add_competition', array_filter([
            'classic' => '1',
            'recurring' => $choice === EventWizardEntryChoice::Series ? '1' : null,
            'organization' => $organizationId,
        ]));
    }
}
