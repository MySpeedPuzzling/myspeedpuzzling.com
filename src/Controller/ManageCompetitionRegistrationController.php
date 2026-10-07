<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\FormData\CompetitionRegistrationFormData;
use SpeedPuzzling\Web\FormType\CompetitionRegistrationFormType;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Query\CountCompetitionRegistrations;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Managed registration of one event or edition (docs/features/competitions-management/registration.md) - its own page
 * and message, so editing the event never changes it. Every existing event stays as it is until its organiser
 * switches management on here.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ManageCompetitionRegistrationController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CountCompetitionRegistrations $countCompetitionRegistrations,
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/registrace-na-udalost/{competitionId}',
            'en' => '/en/manage-event-registration/{competitionId}',
            'es' => '/es/manage-event-registration/{competitionId}',
            'ja' => '/ja/manage-event-registration/{competitionId}',
            'fr' => '/fr/manage-event-registration/{competitionId}',
            'de' => '/de/manage-event-registration/{competitionId}',
        ],
        name: 'manage_competition_registration',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);

        $formData = CompetitionRegistrationFormData::fromCompetition($competition, $this->defaultTimezone($competitionId));
        $form = $this->createForm(CompetitionRegistrationFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            assert($formData->timezone !== null);

            try {
                $opensAt = $formData->opensAtInstant();
            } catch (InvalidLocalTime) {
                $opensAt = false;
                $form->get('opensAt')->addError(new FormError($this->translator->trans('competition.round.form.invalid_local_time')));
            }

            try {
                $closesAt = $formData->closesAtInstant();
            } catch (InvalidLocalTime) {
                $closesAt = false;
                $form->get('closesAt')->addError(new FormError($this->translator->trans('competition.round.form.invalid_local_time')));
            }

            if ($opensAt !== false && $closesAt !== false) {
                $this->messageBus->dispatch(new ChangeCompetitionRegistrationSettings(
                    competitionId: $competitionId,
                    registrationManaged: $formData->registrationManaged,
                    capacity: $formData->capacity,
                    registrationOpensAt: $opensAt,
                    registrationClosesAt: $closesAt,
                    timezone: $formData->timezone,
                    entryFeeText: $formData->trimmedEntryFee(),
                    paymentInstructions: $formData->trimmedPaymentInstructions(),
                ));

                $this->addFlash('success', $this->translator->trans('competition_registration.flash.settings_saved'));

                return $this->redirectToRoute('manage_competition_registration', [
                    'competitionId' => $competitionId,
                    ...array_filter([
                        'return' => $request->query->getString('return'),
                        'return_title' => $request->query->getString('return_title'),
                    ], static fn (string $value): bool => $value !== ''),
                ]);
            }
        }

        // A refused form answers 422 (render() sets it when handed the form itself)
        return $this->render('manage_competition_registration.html.twig', [
            'competition' => $competition,
            'form' => $form,
            'counts' => $this->countCompetitionRegistrations->of($competitionId),
            'is_publicly_visible' => $this->isCompetitionPubliclyVisible->check($competitionId),
        ]);
    }

    /**
     * The zone of the event's rounds, else of its country (or its series' country) - as a new round's form picks it
     * (AddCompetitionRoundController).
     */
    private function defaultTimezone(string $competitionId): string
    {
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);

        if ($rounds !== []) {
            return $rounds[array_key_last($rounds)]->timezone;
        }

        $competition = $this->competitionRepository->get($competitionId);

        return RoundTimezone::resolve(null, $competition->locationCountryCode, $competition->series?->locationCountryCode);
    }
}
