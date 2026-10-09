<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Restructure;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\Exceptions\RoundMovedMeanwhile;
use SpeedPuzzling\Web\Exceptions\RoundNotMovable;
use SpeedPuzzling\Web\FormData\MoveRoundFormData;
use SpeedPuzzling\Web\FormType\MoveRoundFormType;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Query\GetCompetitionRounds;
use SpeedPuzzling\Web\Query\GetRestructureChoices;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionTeamRepository;
use SpeedPuzzling\Web\Results\RestructureChoice;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Move to another event or edition" (docs/features/organizations/README.md "Restructuring tools", D7) →
 * MoveRoundToCompetition. The select offers the events and editions the viewer may edit besides the round's own
 * (admins: every one not rejected, searchable). Every refusal - participants entered, a running stopwatch, a puzzle
 * already in a round of the same category there - is an error on the form (422); success goes to the target's rounds.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MoveCompetitionRoundController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRoundRepository $competitionRoundRepository,
        readonly private GetRestructureChoices $getRestructureChoices,
        readonly private GetCompetitionPermissions $getCompetitionPermissions,
        readonly private GetCompetitionRounds $getCompetitionRounds,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private CompetitionParticipantRoundRepository $participantRoundRepository,
        readonly private CompetitionTeamRepository $competitionTeamRepository,
    ) {
    }

    #[Route(
        path: '/{_locale}/move-round/{roundId}',
        name: 'move_competition_round',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competition = $round->competition;
        $competitionId = $competition->id->toString();

        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);
        $permissions = $this->getCompetitionPermissions->forPlayer($profile->playerId);

        $choices = array_values(array_filter(
            $this->getRestructureChoices->competitions(),
            static fn (RestructureChoice $choice): bool => $choice->id !== $competitionId
                && ($profile->isAdmin || $permissions->canEditCompetition($choice->id)),
        ));

        $options = [];
        $draftMark = $this->translator->trans('restructure.draft_mark');

        foreach ($choices as $choice) {
            $label = $choice->label() . ($choice->isDraft ? ' (' . $draftMark . ')' : '');
            $unique = $label;

            // The same name, series and day twice: numbered, never an id
            for ($number = 2; isset($options[$unique]); $number++) {
                $unique = $label . ' (' . $number . ')';
            }

            $options[$unique] = $choice->id;
        }

        // A round people have entries in cannot move (participants belong to an event) - said up front, no picker
        $hasEntries = $this->participantRoundRepository->findByRound($round) !== []
            || $this->competitionTeamRepository->findByRound($round) !== [];

        $form = $this->createForm(MoveRoundFormType::class, new MoveRoundFormData(), [
            'action' => $this->generateUrl('move_competition_round', ['roundId' => $roundId]),
            'competition_choices' => $options,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $targetId = $form->getData()->competitionId;
            assert($targetId !== null);
            $field = $form->get('competitionId');

            // Asked before the move, so the refusal can name the round there (the handler decides again)
            $conflictingRound = $this->getCompetitionRounds->roundWithPuzzleInCategory(
                competitionId: $targetId,
                puzzleIds: array_values(array_map(
                    static fn (CompetitionRoundPuzzle $roundPuzzle): string => $roundPuzzle->puzzle->id->toString(),
                    $round->roundPuzzles->toArray(),
                )),
                category: $round->category,
            );

            if ($conflictingRound !== null) {
                $field->addError(new FormError($this->translator->trans('restructure.move_round.refused.category', [
                    '%round%' => $conflictingRound,
                ])));
            } else {
                try {
                    $this->messageBus->dispatch(new MoveRoundToCompetition(
                        roundId: $round->id->toString(),
                        competitionId: $competitionId,
                        targetCompetitionId: $targetId,
                        actingPlayerId: $profile->playerId,
                    ));

                    $this->addFlash('success', $this->translator->trans('restructure.move_round.flash.moved', [
                        '%round%' => $round->name,
                    ]));

                    return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $targetId]);
                } catch (RoundNotMovable $exception) {
                    $field->addError(new FormError($this->translator->trans($exception->reason->translationKey())));
                } catch (PuzzleInTwoRoundsOfCategory) {
                    $field->addError(new FormError($this->translator->trans('restructure.move_round.refused.category_generic')));
                } catch (RoundMovedMeanwhile) {
                    $field->addError(new FormError($this->translator->trans('restructure.move_round.refused.moved_meanwhile')));
                }
            }
        }

        return $this->render('restructure/move_round.html.twig', [
            'form' => $form,
            'round' => $round,
            'competition' => $competition,
            'has_choices' => $choices !== [],
            'has_entries' => $hasEntries,
        ]);
    }
}
