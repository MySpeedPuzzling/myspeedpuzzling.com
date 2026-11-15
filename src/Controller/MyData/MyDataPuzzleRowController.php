<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\MyData;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\EditCollectionItemComment;
use SpeedPuzzling\Web\Message\MovePuzzlesToCollection;
use SpeedPuzzling\Web\Query\GetExportablePuzzleSolveSummary;
use SpeedPuzzling\Web\Query\GetPlayerCollections;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Services\CustomFields\SubmittedCustomValues;
use SpeedPuzzling\Web\Services\MyData\CustomFieldsOfMyDataRow;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReturnUrl;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * The row sheet of one puzzle of the signed-in player's collections in "My data" (docs/features/my-data.md "Editing
 * one row"): the comment, the owner's own fields for collection puzzles, and - for members, like everywhere else - the
 * collection it is in. Every write is an existing message: `EditCollectionItemComment`,
 * `SetCollectionItemCustomValues`, `MovePuzzlesToCollection` (the item keeps its id, added date and comment).
 *
 * Everything is checked before anything is written (a value that can not be stored, a collection that is not the
 * player's or already holds the puzzle = 422, nothing saved); then the comment, the fields, the move.
 */
final class MyDataPuzzleRowController extends AbstractController
{
    /** The main collection in the collection select - a custom one is its uuid */
    public const string MAIN_COLLECTION = 'main';

    /** The comment form's own limit (EditCollectionItemCommentFormData: `Length(max: 500)`) */
    public const int COMMENT_MAX_LENGTH = 500;

    /** Symfony's message of that constraint (the `validators` domain) */
    private const string COMMENT_TOO_LONG = 'This value is too long. It should have {{ limit }} character or less.|This value is too long. It should have {{ limit }} characters or less.';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private CollectionRepository $collectionRepository,
        readonly private GetPlayerCollections $getPlayerCollections,
        readonly private GetExportablePuzzleSolveSummary $getExportablePuzzleSolveSummary,
        readonly private CustomFieldsOfMyDataRow $customFieldsOfMyDataRow,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-data/puzzle/{itemId}',
        name: 'my_data_puzzle_row',
        requirements: ['_locale' => MyDataController::LOCALES, 'itemId' => Requirement::UID_RFC4122],
        methods: ['GET', 'POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $itemId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        assert($viewer !== null);

        $item = $this->collectionItemRepository->get($itemId);

        // Somebody else's puzzle is not there at all
        if ($item->player->id->toString() !== $viewer->playerId) {
            throw new CollectionItemNotFound();
        }

        $itemId = $item->id->toString();
        $puzzleId = $item->puzzle->id->toString();
        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';
        $return = ReturnUrl::tryFrom($request->isMethod('POST') ? $request->request->getString('return') : $request->query->getString('return'));
        $returnUrl = $return !== null ? (string) $return : $this->generateUrl('my_data', ['tab' => 'puzzles']);

        // Moving between collections is the members' feature it is on the collection pages
        $collections = [];

        if ($viewer->activeMembership) {
            $collections[self::MAIN_COLLECTION] = $this->translator->trans('my_data.main_collection');

            foreach ($this->getPlayerCollections->byPlayerId($viewer->playerId, true) as $collection) {
                if ($collection->collectionId !== null) {
                    $collections[$collection->collectionId] = $collection->name;
                }
            }
        }

        $storedCollection = $item->collection?->id->toString() ?? self::MAIN_COLLECTION;
        $pickedCollection = $storedCollection;
        $storedComment = $item->comment ?? '';
        $comment = $storedComment;

        $ownFields = $this->customFieldsOfMyDataRow->onCollectionItemOfSignedInViewer($itemId);
        $inputs = $ownFields->storedInputs();
        $problems = [];
        $collectionError = null;
        $commentError = null;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(MyDataResultRowController::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            $comment = trim($request->request->getString('comment'));
            $commentError = self::commentRefusal($comment, $this->translator);
            $inputs = $ownFields->inputsAfter(SubmittedCustomValues::of($request));
            $problems = $ownFields->problems($inputs);

            if ($collections !== [] && $request->request->has('collection')) {
                $pickedCollection = strtolower($request->request->getString('collection'));
            }

            // Before anything is written: the collection is one of the player's own and does not hold the puzzle yet -
            // two items becoming one would take this item (and the comment just typed) away
            if ($pickedCollection !== $storedCollection) {
                $collectionError = $this->collectionRefusal($pickedCollection, $collections, $item);
            }

            if ($problems === [] && $collectionError === null && $commentError === null) {
                $commentChanged = $comment !== trim($storedComment);

                if ($commentChanged) {
                    $this->messageBus->dispatch(new EditCollectionItemComment(
                        collectionItemId: $itemId,
                        playerId: $viewer->playerId,
                        comment: $comment !== '' ? $comment : null,
                    ));
                }

                // After the comment: neither can lose it. The fields never throw (CustomFieldsOfMyDataRow)
                $changedFields = $ownFields->changed($inputs);
                $fieldsSaved = $changedFields === [] || $this->customFieldsOfMyDataRow->saveOnCollectionItemOfSignedInViewer($itemId, $changedFields);
                // null = no move asked for; false = it failed; 0 = the handler moved nothing (the puzzle left the
                // collection meanwhile, or the other one holds it by now)
                $moved = $pickedCollection !== $storedCollection ? $this->move($viewer->playerId, $puzzleId, $storedCollection, $pickedCollection) : null;

                // "Saved" only for what was saved
                if ($commentChanged || ($changedFields !== [] && $fieldsSaved) || ($moved !== null && $moved !== false && $moved > 0)) {
                    $this->addFlash('success', $this->translator->trans('my_data.edit.saved'));
                }

                if ($moved === 0) {
                    $this->addFlash('warning', $this->translator->trans('my_data.edit.not_moved'));
                }

                if ($fieldsSaved === false || $moved === false) {
                    $this->addFlash('warning', $this->translator->trans('my_data.edit.partly_saved'));
                }

                if ($isModal && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                    $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

                    return $this->render('my_data/_row_saved_stream.html.twig');
                }

                return $this->redirect($returnUrl, Response::HTTP_SEE_OTHER);
            }
        }

        $response = $this->render($isModal ? 'my_data/_puzzle_row_modal.html.twig' : 'my_data/puzzle_row.html.twig', [
            'item' => $item,
            'comment' => $comment,
            'collections' => $collections,
            'picked_collection' => $pickedCollection,
            'collection_error' => $collectionError,
            'comment_error' => $commentError,
            'solved' => $this->getExportablePuzzleSolveSummary->forPuzzles($viewer->playerId, [$puzzleId])[$puzzleId] ?? null,
            'my_fields' => new CustomFieldsForm($ownFields->fields, $inputs, $problems),
            'return_url' => $returnUrl,
            'in_modal' => $isModal,
        ]);

        // A refused save is never a 200: Turbo Drive drops a 200 answer to a form submission
        if ($problems !== [] || $collectionError !== null || $commentError !== null) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Same URL, two bodies - and the owner's own values are in the page
        $response->setVary('Turbo-Frame', false);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * @param array<string, string> $collections the collections offered
     */
    private function collectionRefusal(string $pickedCollection, array $collections, CollectionItem $item): null|string
    {
        if (isset($collections[$pickedCollection]) === false) {
            return $this->translator->trans('my_data.edit.collection_unknown');
        }

        try {
            $target = $this->collectionRepository->getOwnedBy($pickedCollection === self::MAIN_COLLECTION ? null : $pickedCollection, $item->player);
        } catch (CollectionNotFound) {
            return $this->translator->trans('my_data.edit.collection_unknown');
        }

        if ($this->collectionItemRepository->findByCollectionPlayerAndPuzzle($target, $item->player, $item->puzzle) !== null) {
            return $this->translator->trans('my_data.edit.collection_holds_it');
        }

        return null;
    }

    /**
     * The comment form's limit, in its words - null = the comment can be stored. Shared with the cell edited in place.
     */
    public static function commentRefusal(string $comment, TranslatorInterface $translator): null|string
    {
        if (mb_strlen($comment) <= self::COMMENT_MAX_LENGTH) {
            return null;
        }

        return $translator->trans(self::COMMENT_TOO_LONG, ['%count%' => self::COMMENT_MAX_LENGTH, '{{ limit }}' => self::COMMENT_MAX_LENGTH], 'validators');
    }

    /**
     * Never throws: the comment is saved and answered.
     *
     * @return false|int how many items the handler moved (SelectedPuzzlesOutcome::$changed) - false = it failed
     */
    private function move(string $playerId, string $puzzleId, string $storedCollection, string $pickedCollection): false|int
    {
        try {
            $outcome = $this->messageBus->dispatch(new MovePuzzlesToCollection(
                playerId: $playerId,
                puzzleIds: [$puzzleId],
                sourceCollectionId: $storedCollection === self::MAIN_COLLECTION ? null : $storedCollection,
                targetCollectionId: $pickedCollection === self::MAIN_COLLECTION ? null : $pickedCollection,
            ))->last(HandledStamp::class)?->getResult();

            return $outcome instanceof SelectedPuzzlesOutcome ? $outcome->changed : false;
        } catch (\Throwable $exception) {
            $this->logger->warning('A puzzle could not be moved to another collection from My data - its comment was saved', [
                'puzzleId' => $puzzleId,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
