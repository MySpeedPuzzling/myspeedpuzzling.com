<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\BorrowRequest;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\BorrowRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\BorrowRequestTooLarge;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Services\PuzzleListSelectionResponder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BorrowAskOutcome;
use SpeedPuzzling\Web\Value\BorrowAskSkipReason;
use SpeedPuzzling\Web\Value\PuzzleList;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * "Send" on the ask-to-borrow sheet (docs/features/wishlist-availability.md): `assignments[<ownerId>][]` = the puzzles
 * asked of that person, `notes[<ownerId>]`, and the sheet's send id (a resent form asks nobody twice). The handler checks
 * everything again; the answer closes the modal, reloads the wishlist and says what was sent and what was left out.
 */
final class SendBorrowRequestsController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'borrow_request';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private PuzzleListSelectionResponder $responder,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/borrow-requests/send',
        name: 'borrow_ask_send',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $asker = $this->retrieveLoggedUserProfile->getProfile();

        if ($asker === null) {
            return $this->redirectToRoute('login');
        }

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $sendId = $request->request->getString('sendId');

        if (Uuid::isValid($sendId) === false) {
            throw $this->createAccessDeniedException();
        }

        $puzzlesByOwner = [];

        foreach ($request->request->all('assignments') as $ownerId => $puzzleIds) {
            if (is_array($puzzleIds)) {
                $puzzlesByOwner[(string) $ownerId] = array_values(array_filter($puzzleIds, is_string(...)));
            }
        }

        $notes = [];

        foreach ($request->request->all('notes') as $ownerId => $note) {
            if (is_string($note)) {
                $notes[(string) $ownerId] = mb_substr($note, 0, 200);
            }
        }

        // Both are HTTP exceptions, unwrapped by UnwrapHttpExceptionMiddleware
        try {
            $envelope = $this->messageBus->dispatch(new AskToBorrowPuzzles($sendId, $asker->playerId, $puzzlesByOwner, $notes));
        } catch (BorrowRequestLimitReached) {
            return $this->refused($request, $asker->playerId, 'borrow_request.flash.limit_reached');
        } catch (BorrowRequestTooLarge) {
            return $this->refused($request, $asker->playerId, 'borrow_request.flash.too_large');
        }

        /** @var BorrowAskOutcome $outcome */
        $outcome = $envelope->last(HandledStamp::class)?->getResult();

        return $this->responder->respond(
            request: $request,
            list: PuzzleList::Wishlist,
            playerId: $asker->playerId,
            outcome: new SelectedPuzzlesOutcome(
                changed: $outcome->puzzlesAsked,
                alreadyThere: $outcome->skippedFor(BorrowAskSkipReason::AlreadyAsked),
                skipped: $outcome->skippedFor(BorrowAskSkipReason::Unavailable) + $outcome->skippedFor(BorrowAskSkipReason::LentOut),
            ),
            toast: $this->translator->trans('borrow_request.flash.sent', [
                '%count%' => $outcome->requestsSent,
                '%puzzles%' => $outcome->puzzlesAsked,
            ]),
            refreshPage: true,
        );
    }

    private function refused(Request $request, string $askerId, string $message): Response
    {
        $this->addFlash('warning', $this->translator->trans($message));

        if ($request->headers->get('Turbo-Frame') !== 'modal-frame') {
            return $this->redirectToRoute('wish_list_detail', ['playerId' => $askerId], Response::HTTP_SEE_OTHER);
        }

        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

        return new Response(
            $this->renderView('_modal_close_stream.html.twig') . '<turbo-stream action="refresh"></turbo-stream>',
            headers: ['Content-Type' => TurboBundle::STREAM_MEDIA_TYPE],
        );
    }
}
