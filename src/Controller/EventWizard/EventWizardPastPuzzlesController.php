<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\EventWizard;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\PuzzleAlreadyInCompetitionRoundCategory;
use SpeedPuzzling\Web\FormData\PastPuzzlesFormData;
use SpeedPuzzling\Web\FormType\PastPuzzlesFormType;
use SpeedPuzzling\Web\FormType\RoundPuzzleFormType;
use SpeedPuzzling\Web\Message\AddCompetitionRound;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Message\SetUpPastEventRounds;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardAccess;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardDefaults;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardRefusal;
use SpeedPuzzling\Web\Services\EventWizard\EventWizardSteps;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\EventWizard\EventWizardStep;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The backfill path's "What did you solve there?" (docs/features/competitions-management/creation-wizard.md
 * "Backfill"): rows of solo / pairs / teams + puzzle (W1's picker - a catalogue puzzle or a new one), and the rounds are
 * made from them - one round per row (the one-round-per-category-per-puzzle invariant), on the event's day with its
 * start time not known (noon in the event's zone, pages show no time), no time limit, named after its category (+ the
 * puzzle when the event has more than one round of that category). One transaction (SetUpPastEventRounds). A row whose
 * puzzle already is in a round of its category is left out (a resent page creates nothing twice). Nothing filled =
 * nothing made - "Link my results" works without rounds too.
 *
 * For whoever may edit the event (COMPETITION_EDIT); anybody else goes on to "Link my results" for it.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EventWizardPastPuzzlesController extends AbstractController
{
    public function __construct(
        private readonly EventWizardAccess $access,
        private readonly EventWizardSteps $eventWizardSteps,
        private readonly CompetitionRepository $competitionRepository,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly PuzzleRepository $puzzleRepository,
        private readonly SecretPuzzleAccess $secretPuzzleAccess,
        private readonly EventWizardDefaults $defaults,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly RoundPuzzleFormType $roundPuzzleFormType,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: '/{_locale}/new-event/{competitionId}/past/puzzles',
        name: 'event_wizard_past_puzzles',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de', 'competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId, #[CurrentUser] UserInterface $user): Response
    {
        $this->access->player();
        $competition = $this->competitionRepository->get($competitionId);

        if ($this->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            return $this->redirectToRoute('event_wizard_link_results', ['competitionId' => $competitionId]);
        }

        $rounds = $this->competitionRoundRepository->ofCompetition($competition);
        $form = $this->createForm(PastPuzzlesFormType::class, new PastPuzzlesFormData(), [
            'action' => $this->generateUrl('event_wizard_past_puzzles', ['competitionId' => $competitionId]),
            'competition_id' => $competitionId,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $message = $this->message($form->getData(), $competition, $rounds, $user->getUserIdentifier());

            if ($message->rounds !== []) {
                try {
                    $this->messageBus->dispatch($message);
                } catch (HandlerFailedException $failure) {
                    $conflict = EventWizardRefusal::find($failure, PuzzleAlreadyInCompetitionRoundCategory::class);

                    if ($conflict === null) {
                        throw $failure;
                    }

                    $form->addError(new FormError($this->translator->trans('event_wizard.puzzles.same_puzzle_in_category', ['%round%' => $conflict->conflictingRoundName])));

                    return $this->page($form, $competitionId, $this->competitionRoundRepository->ofCompetition($this->competitionRepository->get($competitionId)));
                }
            }

            return $this->redirectToRoute('event_wizard_link_results', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, $competitionId, $rounds);
    }

    /**
     * @param list<CompetitionRound> $rounds
     */
    private function message(PastPuzzlesFormData $data, Competition $competition, array $rounds, string $userId): SetUpPastEventRounds
    {
        $competitionId = $competition->id->toString();
        $timezone = RoundTimezone::resolve(null, $competition->locationCountryCode, $competition->series?->locationCountryCode);
        $day = ($competition->dateFrom ?? $this->clock->now())->format('Y-m-d');
        $startsAt = RoundTimezone::toInstant($day . ' 12:00', $timezone);

        /** @var array<string, true> $taken "category puzzle" already in a round, or on an earlier row */
        $taken = [];
        /** @var array<string, int> $perCategory rounds of each category the event will have */
        $perCategory = [];

        foreach ($rounds as $round) {
            $perCategory[$round->category->value] = ($perCategory[$round->category->value] ?? 0) + 1;

            foreach ($round->roundPuzzles as $roundPuzzle) {
                $taken[$round->category->value . ' ' . $roundPuzzle->puzzle->id->toString()] = true;
            }
        }

        $rows = [];

        foreach ($data->filled() as $row) {
            $category = $row->category ?? RoundCategory::Solo;
            $catalogueId = $row->slot->catalogueId();
            $puzzleName = trim((string) $row->slot->puzzle);

            if ($catalogueId !== null) {
                // Another organiser's secret puzzle is not theirs to use (the picker never offers it)
                $this->secretPuzzleAccess->assertVisible($catalogueId, alsoWhileImageHidden: true);
                $key = $category->value . ' ' . $catalogueId;

                if (isset($taken[$key])) {
                    continue;
                }

                $taken[$key] = true;
                $puzzleName = $this->puzzleRepository->get($catalogueId)->name;
            }

            $perCategory[$category->value] = ($perCategory[$category->value] ?? 0) + 1;
            $rows[] = ['category' => $category, 'brand' => trim((string) $row->slot->brand), 'puzzle' => trim((string) $row->slot->puzzle), 'pieces' => $row->slot->piecesCount, 'name' => $puzzleName];
        }

        $setUps = [];

        foreach ($rows as $row) {
            $roundId = Uuid::uuid7();
            /** @var RoundCategory $category */
            $category = $row['category'];
            $name = $this->defaults->roundName($category);

            if (($perCategory[$category->value] ?? 0) > 1) {
                $name .= ' - ' . $row['name'];
            }

            $setUps[] = [
                'round' => new AddCompetitionRound(
                    roundId: $roundId,
                    competitionId: $competitionId,
                    name: mb_substr($name, 0, 255),
                    minutesLimit: null,
                    startsAt: $startsAt,
                    timezone: $timezone,
                    badgeBackgroundColor: null,
                    badgeTextColor: null,
                    category: $category,
                    startTimeKnown: false,
                ),
                'puzzle' => new AddPuzzleToCompetitionRound(
                    roundPuzzleId: Uuid::uuid7(),
                    roundId: $roundId->toString(),
                    userId: $userId,
                    brand: $row['brand'],
                    puzzle: $row['puzzle'],
                    piecesCount: $row['pieces'],
                    puzzlePhoto: null,
                    eans: EanList::fromInputs([]),
                    brandCodes: BrandCodeList::fromInputs([]),
                    hideUntilRoundStarts: false,
                ),
            ];
        }

        return new SetUpPastEventRounds($competitionId, $setUps);
    }

    /**
     * @param FormInterface<PastPuzzlesFormData> $form
     * @param list<CompetitionRound> $rounds
     */
    private function page(FormInterface $form, string $competitionId, array $rounds): Response
    {
        return $this->render('event_wizard/past_puzzles.html.twig', [
            'form' => $form,
            'competition_id' => $competitionId,
            'rounds' => $rounds,
            'progress' => $this->eventWizardSteps->forBackfill($competitionId, EventWizardStep::PastPuzzles),
            'step' => EventWizardStep::PastPuzzles,
            'brand_choices' => $this->roundPuzzleFormType->brandChoices($competitionId),
        ]);
    }
}
