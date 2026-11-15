<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\DataImport;

use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Query\GetImportRowsToFix;
use SpeedPuzzling\Web\Results\ImportRowsToFixGroup;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\ImportToFixPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One answer for every row of a group of "To fix" (docs/features/data-import/pairing-screens.md "To fix"): the value
 * typed for the group's field, "Import these without a time" (a group of the time's field - the answer
 * ImportRowAnswers::NO_TIME), or "Leave these rows out" - and a row the player left out taken in again. One message
 * (AnswerImportRows). POST + CSRF. The group is named by its key, never by its value. "Import all of them without a
 * time" (`no_time_all`) gives that answer to every group about a time at once - a message a group.
 *
 * Answered in place when the request takes Turbo Streams (_to_fix.stream.html.twig): the answered group gives way to a
 * short confirmation where it stood (or stands again with what is left of it), the count and the rows not imported
 * follow - nothing else of the page moves. Without (no script): back to the list (PRG), with the same words as a
 * flash. A value that can not be read for sure: a 422 with the reason at the group's field and what was typed kept -
 * the group's card alone as a stream, else the whole page. Changed meanwhile (the sheet is read again, another tab):
 * the list as it stands, with a notice. Another player's import is a 404.
 */
final class AnswerImportRowsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetImportRowsToFix $getImportRowsToFix,
        readonly private ImportToFixPage $page,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/import/{importId}/to-fix',
        name: 'data_import_rows_answer',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'importId' => Requirement::UUID],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $importId): Response
    {
        if ($this->isCsrfTokenValid(ImportsHomeController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();
        assert($player !== null);

        $form = $request->request;
        $pageNumber = max(1, $form->getInt('page', 1));
        $back = $this->redirectToRoute('data_import_to_fix', ['importId' => $importId, 'page' => $pageNumber]);
        $action = $form->getString('action');
        $key = $form->getString('group');
        $typed = trim(mb_substr($form->getString('answer'), 0, 500));
        $rowId = $form->getString('row');

        if ($action === 'no_time_all') {
            return $this->allTimesWithoutATime($request, $importId, $player->playerId, $pageNumber, $back);
        }

        if ($action === 'take_in') {
            $message = new AnswerImportRows($importId, $player->playerId, [$rowId], leaveOut: false);
            $group = null;
        } else {
            // The group as it stands now - another player's import finds none (and the handler checks it again)
            $group = $this->groupOf($importId, $player->playerId, $key);

            if ($group === null || in_array($action, ['answer', 'no_time', 'leave_out'], true) === false || ($action === 'no_time' && ($group->field !== 'time' || $group->input === null))) {
                // Checks whose import it is: a 404 for anybody else's
                $this->page->parameters($importId, $player->playerId, $pageNumber);
                $this->addFlash('warning', $this->translator->trans('data_import.to_fix.flash.changed_meanwhile'));

                return $back;
            }

            if ($action === 'answer' && ($typed === '' || $group->input === null)) {
                return $this->refused($request, $importId, $player->playerId, $pageNumber, $key, 'empty', $typed);
            }

            $rowIds = $this->getImportRowsToFix->rowIdsOfGroup($importId, $player->playerId, $key, ImportRowAnswers::MAX_ROWS);
            $message = match ($action) {
                'answer' => new AnswerImportRows($importId, $player->playerId, $rowIds, [$group->field => $typed], onlyWithProblem: $key),
                'no_time' => new AnswerImportRows($importId, $player->playerId, $rowIds, [$group->field => ImportRowAnswers::NO_TIME], onlyWithProblem: $key),
                default => new AnswerImportRows($importId, $player->playerId, $rowIds, leaveOut: true, onlyWithProblem: $key),
            };
        }

        try {
            $changed = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $exception) {
            $cause = $exception->getPrevious();

            if ($cause instanceof ImportRowAnswerRefused) {
                return $this->refused($request, $importId, $player->playerId, $pageNumber, $key, $cause->reason === ImportRowAnswerRefused::UNREADABLE ? 'unreadable' : 'refused', $typed);
            }

            if ($cause instanceof ImportRowsChangedMeanwhile) {
                $this->addFlash('warning', $this->translator->trans('data_import.to_fix.flash.changed_meanwhile'));

                return $back;
            }

            throw $exception;
        }

        $changed = is_int($changed) ? $changed : 0;
        $notice = $this->translator->trans(match ($action) {
            'take_in' => 'data_import.to_fix.flash.taken_in',
            'leave_out' => 'data_import.to_fix.flash.left_out',
            'no_time' => 'data_import.to_fix.flash.no_time',
            default => 'data_import.to_fix.flash.answered',
        }, ['%number%' => $changed]);
        $more = $group !== null && $group->rows > $changed && $changed >= ImportRowAnswers::MAX_ROWS
            ? $this->translator->trans('data_import.to_fix.flash.more_remain', ['%number%' => $group->rows - $changed])
            : null;

        if ($request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('data_import/_to_fix.stream.html.twig', [
                ...$this->page->parameters($importId, $player->playerId, $pageNumber),
                'notice' => $more === null ? $notice : $notice . ' ' . $more,
                'answered_key' => $group === null ? null : $key,
                // What is left of the answered group (an answer that fixed only some of its rows) - null: it is gone
                'answered_group' => $group === null ? null : $this->groupOf($importId, $player->playerId, $key),
                'taken_in_row' => $group === null ? $rowId : null,
            ]);
        }

        $this->addFlash('success', $notice);

        if ($more !== null) {
            $this->addFlash('info', $more);
        }

        return $back;
    }

    /**
     * "Import all of them without a time": the answer ImportRowAnswers::NO_TIME for every group about a time that
     * shares an answer (the cell holds a text nobody could read) - a message a group, each with the group's own
     * guard (only the rows that still carry its problem), at most ImportRowAnswers::MAX_ROWS rows a request.
     */
    private function allTimesWithoutATime(Request $request, string $importId, string $playerId, int $pageNumber, Response $back): Response
    {
        // Checks whose import it is: a 404 for anybody else's
        $this->page->parameters($importId, $playerId, $pageNumber);

        $changed = 0;
        $left = ImportRowAnswers::MAX_ROWS;

        foreach ($this->getImportRowsToFix->timeGroups($importId, $playerId) as $group) {
            if ($left <= 0) {
                break;
            }

            $rowIds = $this->getImportRowsToFix->rowIdsOfGroup($importId, $playerId, $group['key'], $left);

            if ($rowIds === []) {
                continue;
            }

            try {
                $fixed = $this->messageBus
                    ->dispatch(new AnswerImportRows($importId, $playerId, $rowIds, ['time' => ImportRowAnswers::NO_TIME], onlyWithProblem: $group['key']))
                    ->last(HandledStamp::class)
                    ?->getResult();
            } catch (HandlerFailedException $exception) {
                $cause = $exception->getPrevious();

                // The sheet is being read again, or the rows were answered meanwhile: what was done stays done
                if ($cause instanceof ImportRowAnswerRefused || $cause instanceof ImportRowsChangedMeanwhile) {
                    break;
                }

                throw $exception;
            }

            $changed += is_int($fixed) ? $fixed : 0;
            $left -= count($rowIds);
        }

        if ($changed === 0) {
            $this->addFlash('warning', $this->translator->trans('data_import.to_fix.flash.changed_meanwhile'));

            return $back;
        }

        $notice = $this->translator->trans('data_import.to_fix.flash.no_time', ['%number%' => $changed]);

        if ($request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render('data_import/_to_fix.stream.html.twig', [
                ...$this->page->parameters($importId, $playerId, $pageNumber),
                'notice' => $notice,
                'answered_key' => null,
                'answered_group' => null,
                'taken_in_row' => null,
                'all_times' => true,
            ]);
        }

        $this->addFlash('success', $notice);

        return $back;
    }

    private function groupOf(string $importId, string $playerId, string $key): null|ImportRowsToFixGroup
    {
        return $this->getImportRowsToFix->groups($importId, $playerId, 1, 0, $key)['groups'][0] ?? null;
    }

    private function refused(Request $request, string $importId, string $playerId, int $page, string $key, string $error, string $typed): Response
    {
        $parameters = $this->page->parameters($importId, $playerId, $page, [$key => $error], [$key => $typed]);
        $group = $key === '' ? null : $this->groupOf($importId, $playerId, $key);

        // In place: the group's card alone, with the reason at its field
        if ($group !== null && $request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            return $this->render(
                'data_import/_to_fix.stream.html.twig',
                [...$parameters, 'notice' => null, 'answered_key' => $key, 'answered_group' => $group, 'taken_in_row' => null, 'refused' => true],
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        return $this->render('data_import/to_fix.html.twig', $parameters, new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
