<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Restructure;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\EditionAlreadyInSeries;
use SpeedPuzzling\Web\Exceptions\EditionNotMovableIntoDraft;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\FormData\MoveEditionFormData;
use SpeedPuzzling\Web\FormType\MoveEditionFormType;
use SpeedPuzzling\Web\Message\MoveEditionToSeries;
use SpeedPuzzling\Web\Query\GetCompetitionPermissions;
use SpeedPuzzling\Web\Query\GetRestructureChoices;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Results\RestructureChoice;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Services\CompetitionUrlField;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Move to another series" (docs/features/organizations/README.md "Restructuring tools") → MoveEditionToSeries. The
 * select offers the series the viewer may edit besides the edition's own (admins: every series not rejected). The
 * address field is needed only when the edition's address is taken in the target series - every refusal is an error on
 * the form (422), success goes to the edition's new page.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MoveEditionController extends AbstractController
{
    public function __construct(
        readonly private CompetitionRepository $competitionRepository,
        readonly private GetRestructureChoices $getRestructureChoices,
        readonly private GetCompetitionPermissions $getCompetitionPermissions,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CompetitionSlugGenerator $slugGenerator,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private CompetitionSeriesRepository $competitionSeriesRepository,
        readonly private CompetitionUrlField $urlField,
    ) {
    }

    #[Route(
        path: '/{_locale}/move-edition/{competitionId}',
        name: 'move_edition',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $edition = $this->competitionRepository->get($competitionId);
        $series = $edition->series;

        // Only an edition moves to another series (a one-time event into a series is a later step, P23)
        if ($series === null) {
            throw new NotFoundHttpException();
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);
        $permissions = $this->getCompetitionPermissions->forPlayer($profile->playerId);
        $currentSeriesId = $series->id->toString();

        $choices = array_values(array_filter(
            $this->getRestructureChoices->series(),
            static fn (RestructureChoice $choice): bool => $choice->id !== $currentSeriesId
                && ($profile->isAdmin || $permissions->canEditSeries($choice->id)),
        ));

        $form = $this->createForm(MoveEditionFormType::class, new MoveEditionFormData(), [
            'action' => $this->generateUrl('move_edition', ['competitionId' => $competitionId]),
            'series_choices' => self::choices($choices, $this->translator->trans('restructure.draft_mark')),
        ]);
        $form->handleRequest($request);
        // After a taken address: a free one in the chosen series, and the address it would live at
        $suggestedSlug = null;
        $slugPrefix = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->seriesId !== null);
            $slug = $data->slug !== null && trim($data->slug) !== '' ? $this->slugGenerator->normalize($data->slug) : null;

            if ($slug !== null && CompetitionSlugGenerator::isValid($slug) === false) {
                $form->get('slug')->addError(new FormError($this->translator->trans('restructure.slug_invalid')));
            } else {
                try {
                    $this->messageBus->dispatch(new MoveEditionToSeries(
                        competitionId: $edition->id->toString(),
                        targetSeriesId: $data->seriesId,
                        actingPlayerId: $profile->playerId,
                        newSlug: $slug,
                    ));

                    $this->addFlash('success', $this->translator->trans('restructure.move_edition.flash.moved'));

                    // The same entity, moved by the handler
                    $moved = $this->competitionRepository->get($competitionId);
                    assert($moved->series !== null);

                    // A series (or an edition) without a slug has no page address - its manage page instead
                    if ($moved->series->slug === null || $moved->slug === null) {
                        return $this->redirectToRoute('manage_competition_series', ['seriesId' => $moved->series->id->toString()]);
                    }

                    return $this->redirectToRoute('edition_detail', [
                        'seriesSlug' => $moved->series->slug,
                        'editionSlug' => $moved->slug,
                    ]);
                } catch (CompetitionSlugTaken) {
                    $form->get('slug')->addError(new FormError($this->translator->trans('restructure.move_edition.slug_taken')));
                    $target = $this->competitionSeriesRepository->get($data->seriesId);
                    $suggestedSlug = $this->freeSlug($slug ?? $edition->slug ?? $this->slugGenerator->normalize($edition->name), $target->id->toString(), $competitionId);
                    $slugPrefix = $target->slug !== null
                        ? $this->urlField->prefix('edition_detail', 'editionSlug', ['seriesSlug' => $target->slug])
                        : null;
                } catch (InvalidCompetitionSlug) {
                    $form->get('slug')->addError(new FormError($this->translator->trans('restructure.slug_invalid')));
                } catch (EditionAlreadyInSeries) {
                    $form->get('seriesId')->addError(new FormError($this->translator->trans('restructure.move_edition.same_series')));
                } catch (EditionNotMovableIntoDraft) {
                    $form->get('seriesId')->addError(new FormError($this->translator->trans('restructure.move_edition.draft_target')));
                }
            }
        }

        return $this->render('restructure/move_edition.html.twig', [
            'form' => $form,
            'edition' => $edition,
            'series' => $series,
            'has_choices' => $choices !== [],
            'suggested_slug' => $suggestedSlug,
            'slug_prefix' => $slugPrefix,
        ]);
    }

    /**
     * The address itself with `-2`, `-3`, … until one is free in the series
     */
    private function freeSlug(string $base, string $seriesId, string $competitionId): string
    {
        $base = $base !== '' ? $base : 'edition';

        $suffix = 2;

        while ($this->slugGenerator->isTaken($base . '-' . $suffix, $seriesId, $competitionId)) {
            $suffix++;
        }

        return $base . '-' . $suffix;
    }

    /**
     * @param list<RestructureChoice> $choices
     * @return array<string, string> label => id
     */
    private static function choices(array $choices, string $draftMark): array
    {
        $options = [];

        foreach ($choices as $choice) {
            $label = $choice->label() . ($choice->isDraft ? ' (' . $draftMark . ')' : '');
            // Two series of one name stay two options
            $options[isset($options[$label]) ? $label . ' #' . substr($choice->id, -4) : $label] = $choice->id;
        }

        return $options;
    }
}
