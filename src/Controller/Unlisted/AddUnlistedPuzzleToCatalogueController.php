<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\FormData\UnlistedCatalogueProposalFormData;
use SpeedPuzzling\Web\FormType\UnlistedCatalogueProposalFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\PairUnlistedPuzzle;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedPuzzlePage;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingOutcome;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * "Add to the catalogue" on one of the signed-in player's unlisted puzzles
 * (docs/features/data-import/unlisted-puzzles.md "Add to the catalogue"): a short form, filled with what the file
 * said, that asks for a photo of the box.
 *
 * **One source of truth for "a player proposes a new puzzle"**: the controller dispatches the add-result form's own
 * message, `AddPuzzle`, unchanged (as the scanner's quick-add does) - a catalogue puzzle waiting for approval, its
 * brand through ManufacturerResolver, its photo stored by the same handler - and then the unlisted page's own
 * `PairUnlistedPuzzle`, unchanged: the results and marks move onto the new puzzle by the very pairing "It is this
 * one" starts, with its questions, its daily guard and its Undo. Nothing of the add form is touched.
 *
 * Both ids travel with the form (`new_puzzle_id`, `request_id`), so the form sent again makes nothing twice.
 *
 * Opened from the list it is the site's modal (`Turbo-Frame: modal-frame`) and answers a success with a Turbo Stream
 * that closes it and changes the row in place; everywhere else it is a page of its own that redirects. A refused
 * form is a 422 either way.
 */
final class AddUnlistedPuzzleToCatalogueController extends AbstractController
{
    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzleRecord $getPuzzleRecord,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/{_locale}/unlisted-puzzles/{unlistedPuzzleId}/add-to-catalogue',
        name: 'unlisted_puzzle_add_to_catalogue',
        requirements: ['_locale' => ImportsHomeController::LOCALES, 'unlistedPuzzleId' => Requirement::UUID],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $unlistedPuzzleId): Response
    {
        // Who asks first: a guest and anybody who is not the owner get "not found"
        $playerId = $this->page->ownerPlayerId();
        $userId = $this->retrieveLoggedUserProfile->getProfile()?->userId;
        $proposal = $this->page->catalogueProposal($unlistedPuzzleId);

        if ($request->isMethod('POST') && $this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';
        /** @var array{id: string, name: string, brandText: null|string, piecesCount: null|int, eans: list<string>, brandCodes: list<string>, pairedPuzzleId: null|string} $unlisted */
        $unlisted = $proposal['puzzle'];

        // The ids one opened form stands for. A puzzle with that id that is the player's own: the form arrived again
        $newPuzzleId = self::submittedIdOrNew($request, 'new_puzzle_id');
        $requestId = self::submittedIdOrNew($request, 'request_id');
        $resent = false;

        if ($request->isMethod('POST')) {
            $existing = $this->getPuzzleRecord->byId($newPuzzleId->toString());

            if ($existing !== null && $existing->addedById === $playerId) {
                $resent = true;
            } elseif ($existing !== null) {
                // Somebody else's puzzle has that id - never theirs to touch
                $newPuzzleId = Uuid::uuid7();
            }
        }

        // The form sent again after it went through: the same answer
        if ($resent && $unlisted['pairedPuzzleId'] === $newPuzzleId->toString()) {
            return $this->added($request, $isModal, $unlisted, $newPuzzleId->toString());
        }

        // Paired, or at work: there is nothing to add any more - its own page says what is going on
        if ($proposal['can_choose'] !== true) {
            return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
        }

        $data = new UnlistedCatalogueProposalFormData();
        $data->name = $unlisted['name'];
        // The catalogue's own name of the brand when the file's brand is one of ours - it is then that brand
        $data->brand = is_string($proposal['brand_name']) ? $proposal['brand_name'] : $unlisted['brandText'];
        $data->piecesCount = $unlisted['piecesCount'];
        // Only codes the catalogue would take: a code of the file that is none must not stand in the way
        $data->ean = implode(', ', array_filter($unlisted['eans'], EanList::isAcceptedNewCode(...))) ?: null;
        $data->brandCode = implode(', ', $unlisted['brandCodes']) ?: null;

        $form = $this->createForm(UnlistedCatalogueProposalFormType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $userId !== null) {
            if ($proposal['paused'] === true) {
                $form->addError(new FormError($this->translator->trans('unlisted_puzzles.flash.paused')));
            } elseif ($proposal['limit_reached'] === true && $resent === false) {
                $form->addError(new FormError($this->translator->trans('unlisted_puzzles.catalogue.limit_reached', ['%limit%' => $proposal['limit']])));
            } elseif ($this->add($form, $data, $userId, $playerId, $unlistedPuzzleId, $newPuzzleId, $requestId)) {
                return $this->added($request, $isModal, $unlisted, $newPuzzleId->toString());
            }
        }

        $response = $this->render($isModal ? 'unlisted_puzzles/_add_to_catalogue_modal.html.twig' : 'unlisted_puzzles/add_to_catalogue.html.twig', [
            ...$proposal,
            'form' => $form->createView(),
            'new_puzzle_id' => $newPuzzleId->toString(),
            'request_id' => $requestId->toString(),
            // "It is this one" on a likely catalogue puzzle is a request of its own - never the form's
            'pair_request_id' => $proposal['request_id'],
            'is_modal' => $isModal,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setVary('Turbo-Frame', false);

        return $response;
    }

    /**
     * The two messages, in their order. False: the form carries the reason and is shown again.
     *
     * @param FormInterface<UnlistedCatalogueProposalFormData> $form
     */
    private function add(
        FormInterface $form,
        UnlistedCatalogueProposalFormData $data,
        string $userId,
        string $playerId,
        string $unlistedPuzzleId,
        UuidInterface $newPuzzleId,
        UuidInterface $requestId,
    ): bool {
        if ($data->photo === null || $data->piecesCount === null) {
            return false;
        }

        try {
            // Sent again, the puzzle is already there and the handler creates nothing
            $this->messageBus->dispatch(new AddPuzzle(
                puzzleId: $newPuzzleId,
                userId: $userId,
                puzzleName: trim((string) $data->name),
                brand: trim((string) $data->brand),
                piecesCount: $data->piecesCount,
                puzzlePhoto: $data->photo,
                eans: EanList::fromInputs([$data->ean]),
                brandCodes: BrandCodeList::fromInputs([$data->brandCode]),
            ));
        } catch (MessengerException $exception) {
            // The photo could not be stored, or the puzzle not written: nothing was created, the player tries again
            $this->logger->error('Add to the catalogue: the new puzzle of an unlisted puzzle could not be created.', [
                'exception' => $exception,
                'unlisted_puzzle_id' => $unlistedPuzzleId,
            ]);
            $form->addError(new FormError($this->translator->trans('unlisted_puzzles.catalogue.failed')));

            return false;
        }

        $outcome = $this->messageBus->dispatch(new PairUnlistedPuzzle(
            $requestId->toString(),
            $unlistedPuzzleId,
            $playerId,
            $newPuzzleId->toString(),
        ))->last(HandledStamp::class)?->getResult();

        if ($outcome instanceof UnlistedPairingOutcome && $outcome->isStarted() === false) {
            // The puzzle is in the catalogue's queue by now; the same form again only matches it
            $form->addError(new FormError($this->translator->trans('unlisted_puzzles.flash.' . $outcome->status)));

            return false;
        }

        return true;
    }

    /**
     * @param array{id: string, name: string, ...} $unlisted
     */
    private function added(Request $request, bool $isModal, array $unlisted, string $puzzleId): Response
    {
        if ($isModal && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            $response = $this->render('unlisted_puzzles/_add_to_catalogue_stream.html.twig', [
                'puzzle' => $unlisted,
                'catalogue_puzzle_id' => $puzzleId,
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        $this->addFlash('success', $this->translator->trans('unlisted_puzzles.catalogue.added'));

        return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlisted['id']]);
    }

    private static function submittedIdOrNew(Request $request, string $name): UuidInterface
    {
        $submitted = $request->request->getString($name);

        return Uuid::isValid($submitted) ? Uuid::fromString($submitted) : Uuid::uuid7();
    }
}
