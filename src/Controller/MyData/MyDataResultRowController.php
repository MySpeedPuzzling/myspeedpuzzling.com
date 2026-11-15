<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\MyData;

use SpeedPuzzling\Web\Query\GetMyDataResultContext;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Results\MyData\MyDataResultEdit;
use SpeedPuzzling\Web\Results\MyData\MyDataResultEditOutcome;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedCustomValues;
use SpeedPuzzling\Web\Services\MyData\CustomFieldsOfMyDataRow;
use SpeedPuzzling\Web\Services\MyData\MyDataResultEditor;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\FirstTryResolution;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The row sheet of one result in "My data" (docs/features/my-data.md "Editing one row"): the time, the day, first
 * try, the comment and the signed-in player's own fields of a result they tracked or took part in. Everything else
 * (the puzzle, the people, the photo, the event) is read-only here and edited in the full edit form.
 *
 * Saving is MyDataResultEditor (the edit form's checks and message), then the player's own values through their own
 * message - checked before anything is written, so a refusal leaves nothing half-saved. A refused save answers 422
 * with the edit form's notice; a saved one goes back to the table (in the modal: the modal closes and the table
 * under it refreshes).
 */
final class MyDataResultRowController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'my_data_edit';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MyDataResultEditor $editor,
        readonly private CustomFieldsOfMyDataRow $customFieldsOfMyDataRow,
        readonly private GetMyDataResultContext $getMyDataResultContext,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-data/result/{timeId}',
        name: 'my_data_result_row',
        // Any uuid-shaped id, like the result detail: the fixture times are no valid RFC 4122 versions
        requirements: ['_locale' => MyDataController::LOCALES, 'timeId' => Requirement::UID_RFC4122],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $timeId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        assert($viewer !== null);

        // Somebody else's result is not there at all
        $time = $this->editor->rowOf($viewer, $timeId);
        $timeId = $time->timeId;
        $editable = $time->isEditableBy($viewer->playerId);

        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';
        $return = ReturnUrl::tryFrom($request->isMethod('POST') ? $request->request->getString('return') : $request->query->getString('return'));
        $returnUrl = $return !== null ? (string) $return : $this->generateUrl('my_data');

        $ownFields = $this->customFieldsOfMyDataRow->onResultOfSignedInViewer($timeId);
        $inputs = $ownFields->storedInputs();
        $problems = [];

        $edit = MyDataResultEdit::ofStored($time);
        $outcome = MyDataResultEditOutcome::nothingToSave();
        $firstTryResolution = FirstTryResolution::None;
        $duplicateConfirmed = false;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            if ($editable) {
                $stored = $edit;
                $timed = $time->time !== null;
                $edit = new MyDataResultEdit(
                    hours: $timed ? $request->request->getString('hours') : $stored->hours,
                    minutes: $timed ? $request->request->getString('minutes') : $stored->minutes,
                    seconds: $timed ? $request->request->getString('seconds') : $stored->seconds,
                    day: $request->request->getString('day'),
                    // Asked of results with a time only, like in the edit form
                    firstAttempt: $timed ? $request->request->getBoolean('first_try') : $stored->firstAttempt,
                    comment: $request->request->getString('comment'),
                );
            }

            $inputs = $ownFields->inputsAfter(SubmittedCustomValues::of($request));
            // Checked before anything is written: a value that can not be stored refuses the save as a whole
            $problems = $ownFields->problems($inputs);

            if ($problems === []) {
                $firstTryResolution = FirstTryResolution::tryFrom($request->request->getString('first_try_resolution')) ?? FirstTryResolution::None;
                $duplicateConfirmed = $request->request->getString('duplicate_confirmed') === '1';

                if ($editable) {
                    $outcome = $this->editor->save($viewer, $time, $edit, $firstTryResolution, $duplicateConfirmed, $request->request->getString('pace_confirmed'));
                }

                if ($outcome->isRefused() === false) {
                    $changedFields = $ownFields->changed($inputs);
                    $fieldsSaved = $changedFields === [] || $this->customFieldsOfMyDataRow->saveOnResultOfSignedInViewer($timeId, $changedFields);

                    if ($fieldsSaved === false) {
                        $this->addFlash('warning', $this->translator->trans('my_data.edit.fields_not_saved'));
                    } elseif ($outcome->saved) {
                        $this->addFlash('success', $this->translator->trans('flashes.time_edited'));
                    } elseif ($changedFields !== []) {
                        $this->addFlash('success', $this->translator->trans('my_fields.values.saved'));
                    }

                    // Gated on the frame header, not only on the stream format - Turbo asks for streams on every form
                    // submission
                    if ($isModal && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                        return $this->render('my_data/_row_saved_stream.html.twig');
                    }

                    return $this->redirect($returnUrl, Response::HTTP_SEE_OTHER);
                }
            }
        }

        $response = $this->render($isModal ? 'my_data/_result_row_modal.html.twig' : 'my_data/result_row.html.twig', [
            'time' => $time,
            'editable' => $editable,
            'edit' => $edit,
            'outcome' => $outcome,
            'first_try_resolution' => $firstTryResolution->value,
            'duplicate_confirmed' => $duplicateConfirmed,
            'group_players' => MyDataResultEditor::storedGroupPlayers($time),
            'my_fields' => new CustomFieldsForm($ownFields->fields, $inputs, $problems),
            'context' => $this->getMyDataResultContext->ofResult($timeId, $viewer->playerId),
            'return_url' => $returnUrl,
            'in_modal' => $isModal,
        ]);

        // A refused save is never a 200: Turbo Drive drops a 200 answer to a form submission
        if ($problems !== [] || $outcome->isRefused()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Same URL, two bodies - and never anybody's but the viewer's
        $response->setVary('Turbo-Frame', false);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
