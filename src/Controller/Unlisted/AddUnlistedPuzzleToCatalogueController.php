<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Unlisted;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Controller\DataImport\ImportsHomeController;
use SpeedPuzzling\Web\Exceptions\PuzzleIdTaken;
use SpeedPuzzling\Web\FormData\UnlistedCatalogueProposalFormData;
use SpeedPuzzling\Web\FormType\UnlistedCatalogueProposalFormType;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\PairUnlistedPuzzle;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Query\GetUnlistedPuzzles;
use SpeedPuzzling\Web\Results\ImportCataloguePuzzle;
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
 * **No puzzle without its match**: what would refuse the match (the safety switch, the rate limit, a match at work)
 * is asked before `AddPuzzle`. Should the match be refused all the same, the form says "the puzzle is in the
 * catalogue; we could not move your results yet", remembers that puzzle for this unlisted puzzle and leads with it
 * ("It is this one") - as it does after a match that was taken back. The form then carries that puzzle's id, so
 * adding "again" never makes a second one.
 *
 * Opened from the list it is the site's modal (`Turbo-Frame: modal-frame`) and answers a success with a Turbo Stream
 * that closes it and changes the row in place; everywhere else it is a page of its own that redirects. A refused
 * form is a 422 either way.
 */
final class AddUnlistedPuzzleToCatalogueController extends AbstractController
{
    private const string SESSION_KEY = 'unlisted_puzzles.catalogue_created';
    private const string ADDED = 'added';
    private const string REFUSED = 'refused';
    private const string ID_TAKEN = 'id_taken';
    private const string MATCH_REFUSED = 'match_refused';

    public function __construct(
        readonly private UnlistedPuzzlePage $page,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPuzzleRecord $getPuzzleRecord,
        readonly private GetUnlistedPuzzles $getUnlistedPuzzles,
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
        $isModal = $request->headers->get('Turbo-Frame') === 'modal-frame';

        // A request above PHP's post_max_size arrives without any field - its token with them. That is a photo too
        // large, not a forged form: said as such, never a bare 403
        $tooLarge = $request->isMethod('POST')
            && $request->request->count() === 0
            && $request->files->count() === 0
            && (int) $request->headers->get('Content-Length') > 0;

        if ($request->isMethod('POST') && $tooLarge === false && $this->isCsrfTokenValid(UnlistedPuzzlePage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $proposal = $this->page->catalogueProposal($unlistedPuzzleId, $this->rememberedPuzzleId($request, $unlistedPuzzleId));
        /** @var array{id: string, name: string, brandText: null|string, piecesCount: null|int, eans: list<string>, brandCodes: list<string>, pairedPuzzleId: null|string} $unlisted */
        $unlisted = $proposal['puzzle'];
        $ownPuzzle = $proposal['own_puzzle'];

        // The ids one opened form stands for. The puzzle the player already added for this unlisted puzzle is the
        // one the form goes on with - adding "again" corrects it (AddPuzzleHandler), never makes a second one
        $newPuzzleId = self::submittedIdOrNew($request, 'new_puzzle_id', $ownPuzzle instanceof ImportCataloguePuzzle ? $ownPuzzle->puzzleId : null);
        $requestId = self::submittedIdOrNew($request, 'request_id');
        $resent = false;

        if ($request->isMethod('POST')) {
            $existing = $this->getPuzzleRecord->byId($newPuzzleId->toString());

            if ($existing !== null && $existing->addedById === $playerId) {
                // A puzzle with that id that is the player's own: the form arrived again
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

        // Matched, or at work: there is nothing to add any more - its own page says what is going on
        if ($proposal['can_choose'] !== true) {
            if ($isModal === false) {
                return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlistedPuzzleId]);
            }

            // A list that went stale: a redirect would leave the modal's frame empty - say it in the frame
            $response = $this->render('unlisted_puzzles/_add_to_catalogue_gone.html.twig', ['puzzle' => $unlisted], new Response(status: $request->isMethod('POST') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

            return self::privately($response);
        }

        $form = $this->formOf($unlisted, $proposal['brand_name']);
        $notice = null;

        if ($tooLarge) {
            $notice = $this->translator->trans('unlisted_puzzles.catalogue.form.photo_too_large', ['%megabytes%' => intdiv(UnlistedCatalogueProposalFormType::MAX_PHOTO_BYTES, 1_000_000)]);
        } else {
            $form->handleRequest($request);
        }

        $data = $form->getData();

        if ($tooLarge === false && $form->isSubmitted() && $form->isValid() && $userId !== null) {
            // Everything that would refuse the match, before a puzzle is created: a new puzzle nobody's results
            // moved onto would wait in the moderators' queue for nothing
            if ($proposal['paused'] === true) {
                $form->addError(new FormError($this->translator->trans('unlisted_puzzles.flash.paused')));
            } elseif ($proposal['rate_limited'] === true) {
                $form->addError(new FormError($this->translator->trans('unlisted_puzzles.flash.rate_limited')));
            } elseif ($proposal['limit_reached'] === true && $resent === false) {
                $form->addError(new FormError($this->translator->trans('unlisted_puzzles.catalogue.limit_reached', ['%limit%' => $proposal['limit']])));
            } else {
                $outcome = $this->add($form, $data, $userId, $playerId, $unlistedPuzzleId, $newPuzzleId, $requestId);

                if ($outcome === self::ADDED) {
                    $this->forgetPuzzleId($request, $unlistedPuzzleId);

                    return $this->added($request, $isModal, $unlisted, $newPuzzleId->toString());
                }

                if ($outcome === self::ID_TAKEN) {
                    // The id was user input - with a fresh one the next submit goes through
                    $newPuzzleId = Uuid::uuid7();
                }

                if ($outcome === self::MATCH_REFUSED) {
                    // The puzzle is in the catalogue's queue, its results are not on it yet: the form is shown again
                    // leading with that very puzzle ("It is this one"), and goes on with its id
                    $this->rememberPuzzleId($request, $unlistedPuzzleId, $newPuzzleId->toString());
                    $proposal = $this->page->catalogueProposal($unlistedPuzzleId, $newPuzzleId->toString());
                    $form = $this->formOf($unlisted, $proposal['brand_name'], $data);
                    $notice = $this->translator->trans('unlisted_puzzles.catalogue.match_refused');
                }
            }
        }

        $refused = $notice !== null || $form->isSubmitted();
        $response = $this->render($isModal ? 'unlisted_puzzles/_add_to_catalogue_modal.html.twig' : 'unlisted_puzzles/add_to_catalogue.html.twig', [
            ...$proposal,
            'form' => $form->createView(),
            'notice' => $notice,
            'new_puzzle_id' => $newPuzzleId->toString(),
            'request_id' => $requestId->toString(),
            // "It is this one" on a likely catalogue puzzle is a request of its own - never the form's
            'pair_request_id' => $proposal['request_id'],
            'is_modal' => $isModal,
            // The form was sent: it stays open under the puzzle the player already added
            'form_open' => $form->isSubmitted() || $tooLarge,
        ], new Response(status: $refused ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

        return self::privately($response);
    }

    /**
     * The two messages, in their order.
     *
     * @param FormInterface<UnlistedCatalogueProposalFormData> $form
     *
     * @return self::ADDED|self::REFUSED|self::ID_TAKEN|self::MATCH_REFUSED
     */
    private function add(
        FormInterface $form,
        UnlistedCatalogueProposalFormData $data,
        string $userId,
        string $playerId,
        string $unlistedPuzzleId,
        UuidInterface $newPuzzleId,
        UuidInterface $requestId,
    ): string {
        if ($data->photo === null || $data->piecesCount === null) {
            return self::REFUSED;
        }

        try {
            // Sent again, the puzzle is already there: the handler creates nothing (and corrects one nothing uses yet)
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
        } catch (MessengerException | PuzzleIdTaken $exception) {
            $form->addError(new FormError($this->translator->trans('unlisted_puzzles.catalogue.failed')));

            // An id that is somebody else's puzzle by now (or a secret one): user input, nothing of ours failed
            if (self::isIdTaken($exception)) {
                $this->logger->info('Add to the catalogue: the new puzzle\'s id was taken.', [
                    'exception' => $exception,
                    'unlisted_puzzle_id' => $unlistedPuzzleId,
                ]);

                return self::ID_TAKEN;
            }

            // The photo could not be stored, or the puzzle not written: nothing was created, the player tries again
            $this->logger->error('Add to the catalogue: the new puzzle of an unlisted puzzle could not be created.', [
                'exception' => $exception,
                'unlisted_puzzle_id' => $unlistedPuzzleId,
            ]);

            return self::REFUSED;
        }

        $outcome = $this->messageBus->dispatch(new PairUnlistedPuzzle(
            $requestId->toString(),
            $unlistedPuzzleId,
            $playerId,
            $newPuzzleId->toString(),
        ))->last(HandledStamp::class)?->getResult();

        if ($outcome instanceof UnlistedPairingOutcome && $outcome->isStarted() === false) {
            return self::MATCH_REFUSED;
        }

        return self::ADDED;
    }

    /**
     * The form, filled with what the file said - or with what the player just sent.
     *
     * @param array{name: string, brandText: null|string, piecesCount: null|int, eans: list<string>, brandCodes: list<string>, ...} $unlisted
     *
     * @return FormInterface<UnlistedCatalogueProposalFormData>
     */
    private function formOf(array $unlisted, mixed $catalogueBrandName, null|UnlistedCatalogueProposalFormData $sent = null): FormInterface
    {
        $data = new UnlistedCatalogueProposalFormData();
        $data->name = $sent->name ?? $unlisted['name'];
        // The catalogue's own name of the brand when the file's brand is one of ours - it is then that brand
        $data->brand = $sent->brand ?? (is_string($catalogueBrandName) ? $catalogueBrandName : $unlisted['brandText']);
        $data->piecesCount = $sent->piecesCount ?? $unlisted['piecesCount'];
        // Only codes the catalogue would take: a code of the file that is none must not stand in the way
        $data->ean = $sent !== null ? $sent->ean : (implode(', ', array_filter($unlisted['eans'], EanList::isAcceptedNewCode(...))) ?: null);
        $data->brandCode = $sent !== null ? $sent->brandCode : (implode(', ', $unlisted['brandCodes']) ?: null);

        return $this->createForm(UnlistedCatalogueProposalFormType::class, $data);
    }

    /**
     * The catalogue puzzle the form created for this unlisted puzzle while its match was refused - kept in the
     * player's session so the form opened again goes on with the same puzzle. (After a match that was taken back
     * the puzzle is found by the match's own record - UnlistedPuzzlePage::catalogueProposal().)
     */
    private function rememberedPuzzleId(Request $request, string $unlistedPuzzleId): null|string
    {
        $remembered = $request->hasSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        $puzzleId = is_array($remembered) ? $remembered[$unlistedPuzzleId] ?? null : null;

        return is_string($puzzleId) && Uuid::isValid($puzzleId) ? $puzzleId : null;
    }

    private function rememberPuzzleId(Request $request, string $unlistedPuzzleId, string $puzzleId): void
    {
        if ($request->hasSession() === false) {
            return;
        }

        $remembered = $request->getSession()->get(self::SESSION_KEY);
        $remembered = is_array($remembered) ? array_slice($remembered, -19, null, true) : [];
        $remembered[$unlistedPuzzleId] = $puzzleId;
        $request->getSession()->set(self::SESSION_KEY, $remembered);
    }

    private function forgetPuzzleId(Request $request, string $unlistedPuzzleId): void
    {
        $remembered = $request->hasSession() ? $request->getSession()->get(self::SESSION_KEY) : null;

        if (is_array($remembered) && array_key_exists($unlistedPuzzleId, $remembered)) {
            unset($remembered[$unlistedPuzzleId]);
            $request->getSession()->set(self::SESSION_KEY, $remembered);
        }
    }

    /**
     * Whatever wrapped it on the way out of the bus.
     */
    private static function isIdTaken(\Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PuzzleIdTaken) {
                return true;
            }
        }

        return false;
    }

    private static function privately(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->setVary('Turbo-Frame', false);

        return $response;
    }

    /**
     * @param array{id: string, name: string, unlistedLeft?: int, results?: list<array{unlisted: bool, ...}>, ...} $unlisted
     */
    private function added(Request $request, bool $isModal, array $unlisted, string $puzzleId): Response
    {
        if ($isModal && TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            // The list's own numbers, without this puzzle: its row just changed, the line above must not stay behind.
            // (It is on the list until the worker moved everything - what was read before the match says how much)
            $summary = $this->getUnlistedPuzzles->summary($this->page->ownerPlayerId());
            $stillListed = ($unlisted['unlistedLeft'] ?? 0) > 0;
            $unlistedResults = count(array_filter($unlisted['results'] ?? [], static fn (array $result): bool => $result['unlisted']));

            $response = $this->render('unlisted_puzzles/_add_to_catalogue_stream.html.twig', [
                'puzzle' => $unlisted,
                'catalogue_puzzle_id' => $puzzleId,
                'summary' => [
                    'puzzles' => max(0, $summary['puzzles'] - ($stillListed ? 1 : 0)),
                    'results' => max(0, $summary['results'] - ($stillListed ? $unlistedResults : 0)),
                ],
            ]);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        }

        $this->addFlash('success', $this->translator->trans('unlisted_puzzles.catalogue.added'));

        return $this->redirectToRoute('unlisted_puzzle', ['unlistedPuzzleId' => $unlisted['id']]);
    }

    private static function submittedIdOrNew(Request $request, string $name, null|string $otherwise = null): UuidInterface
    {
        $submitted = $request->request->getString($name);

        if (Uuid::isValid($submitted)) {
            return Uuid::fromString($submitted);
        }

        return $otherwise !== null && Uuid::isValid($otherwise) ? Uuid::fromString($otherwise) : Uuid::uuid7();
    }
}
