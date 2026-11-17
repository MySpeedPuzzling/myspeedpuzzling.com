<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\DuplicateCompetitionFormData;
use SpeedPuzzling\Web\FormType\DuplicateCompetitionFormType;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetPreviousEditionDay;
use SpeedPuzzling\Web\Results\CompetitionRoundForManagement;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\EventDateShift;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\NextEditionName;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Duplicate" of a one-time event or an edition (docs/features/competitions-management/README.md "Duplicating an
 * event") - from the ⋯ menu, "You organize" and the edit page (and the creation wizard). GET asks for the copy's new
 * date and name, POST makes the copy: its rounds and registration window move by the same number of days
 * (DuplicateCompetition `dateFrom`, EventDateShift), then a 303 to the new draft's edit page. A plain page - it works
 * from inside the events list's frames (the links leave them, `_top`) and without JavaScript.
 *
 * Rounds are copied without their puzzles, so a `pick` round's copy is "One result for the round" (competition toolkit
 * G3, round-formats.md "Known edges") - the page and a second flash name each such round and its format, so the organiser
 * sets it again once the copy has its puzzles.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DuplicateCompetitionController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the form used to sit in every ⋯ menu and on "You organize" - kept for the
    // form of this page, so a POST of an older page still carries a valid token
    public const string CSRF_TOKEN_ID = 'competition_duplicate';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private CompetitionRepository $competitionRepository,
        readonly private EventDateShift $eventDateShift,
        readonly private GetPreviousEditionDay $getPreviousEditionDay,
        readonly private FormFactoryInterface $formFactory,
        readonly private GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
    ) {
    }

    #[Route(
        path: '/{_locale}/duplicate-event/{competitionId}',
        name: 'duplicate_competition',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $competitionId = strtolower($competitionId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if ($request->isMethod('POST') && $this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw $this->createAccessDeniedException();
        }

        $source = $this->competitionRepository->get($competitionId);
        $sourceDay = $this->eventDateShift->firstDayOf($source);
        $seriesId = $source->series?->id->toString();

        // The next edition of a series: the number in its name +1 and the date after the same gap as the one before it
        $proposedDay = null;

        if ($seriesId !== null && $sourceDay !== null) {
            $previousDay = $this->getPreviousEditionDay->before($seriesId, $competitionId, $sourceDay);

            if ($previousDay !== null) {
                $gap = (int) $previousDay->diff(new DateTimeImmutable($sourceDay->format('Y-m-d'), new DateTimeZone('UTC')))->format('%a');
                $proposedDay = new DateTimeImmutable($sourceDay->format('Y-m-d'), new DateTimeZone('UTC'))->modify(sprintf('+%d days', $gap));
            }
        }

        $formData = new DuplicateCompetitionFormData(
            name: $seriesId !== null ? NextEditionName::of($source->name) : $source->name,
            dateFrom: $proposedDay,
        );
        $form = $this->formFactory->createNamed('', DuplicateCompetitionFormType::class, $formData);
        // The rounds whose format the copy does not keep (it has no puzzles to pick from)
        $pickRounds = array_values(array_filter(
            $this->getCompetitionRoundsForManagement->ofCompetition($competitionId),
            static fn (CompetitionRoundForManagement $round): bool => $round->puzzleFormat === RoundPuzzleFormat::Pick && $round->puzzlesToSolve !== null,
        ));
        $form->handleRequest($request);

        // The date is what the copy is about - its own message rather than the validator's generic one (an unreadable
        // date has its error already)
        if ($form->isSubmitted() && $formData->dateFrom === null && $form->get('dateFrom')->getErrors()->count() === 0) {
            $form->get('dateFrom')->addError(new FormError($this->translator->trans('event_duplicate.form.date_required')));
        }

        if ($form->isSubmitted() && $form->isValid() && $formData->dateFrom !== null) {
            $newCompetitionId = Uuid::uuid7();

            $this->messageBus->dispatch(new DuplicateCompetition(
                newCompetitionId: $newCompetitionId,
                sourceCompetitionId: $competitionId,
                actingPlayerId: $profile->playerId,
                name: trim((string) $formData->name),
                dateFrom: $formData->dateFrom,
            ));

            $this->addFlash('success', $this->translator->trans('event_duplicate.flash_dated', [
                '%date%' => $this->formatDay($formData->dateFrom, $request->getLocale()),
            ]));

            if ($pickRounds !== []) {
                $this->addFlash('warning', $this->translator->trans('event_duplicate.formats.flash', [
                    '%rounds%' => implode('; ', array_map(fn (CompetitionRoundForManagement $round): string => $this->translator->trans('event_duplicate.formats.round', [
                        '%round%' => $round->name,
                        '%format%' => $this->formatName($round),
                    ]), $pickRounds)),
                ]));
            }

            return $this->redirectToRoute(
                'edit_competition',
                ['competitionId' => $newCompetitionId->toString()],
                Response::HTTP_SEE_OTHER,
            );
        }

        // An invalid submit answers 422 (render() with the form itself)
        return $this->render('duplicate_competition.html.twig', [
            'form' => $form,
            'source' => $source,
            'source_day' => $sourceDay,
            'pick_rounds' => $pickRounds,
        ]);
    }

    /**
     * The round's format as the round pages name it (competition_toolkit/_round_format_summary.html.twig)
     */
    private function formatName(CompetitionRoundForManagement $round): string
    {
        $toSolve = (int) $round->puzzlesToSolve;

        if ($toSolve >= $round->puzzleCount) {
            return $this->translator->trans('competition_toolkit.round_format.name.all', ['%count%' => $round->puzzleCount]);
        }

        return $this->translator->trans('competition_toolkit.round_format.name.pick', ['%count%' => $toSolve, '%puzzles%' => $round->puzzleCount]);
    }

    private function formatDay(DateTimeImmutable $day, string $locale): string
    {
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::LONG, IntlDateFormatter::NONE, $day->getTimezone());
        $formatted = $formatter->format($day);

        return $formatted !== false ? $formatted : $day->format('Y-m-d');
    }
}
