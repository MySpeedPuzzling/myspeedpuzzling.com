<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleImageChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleIsStillSecret;
use SpeedPuzzling\Web\Exceptions\PuzzleOriginalImageNotArchived;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\RejectPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Security\PuzzleModerationVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Turbo\TurboBundle;

/**
 * Approve or reject one card of the picture review (PuzzleChangeRequestPicturesController) - through the existing
 * messages, recorded as every other decision: approving applies the proposed picture only (`selectedFields: image`,
 * the rest of the puzzle as it is now, refused when the puzzle changed since the card was shown), rejecting tells the
 * proposer a short reason (a default one unless the moderator typed one).
 *
 * Turbo submits it with a stream answer that replaces just that card - the page is not loaded again. Without
 * JavaScript it redirects back to the grid with a flash.
 */
final class DecidePictureProposalController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'picture_proposal_decision';

    // What the proposer reads when the moderator typed nothing - neutral, nobody is blamed
    public const string DEFAULT_REJECTION_REASON = 'Thank you - the current picture stays.';

    public const int REJECTION_REASON_MAX_LENGTH = 500;

    public function __construct(
        private readonly GetPuzzleChangeRequests $getPuzzleChangeRequests,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/admin/puzzle-change-requests/{id}/picture-decision',
        name: 'admin_puzzle_change_request_picture_decision',
        methods: ['POST'],
    )]
    #[IsGranted(PuzzleModerationVoter::PUZZLE_MODERATION_ACCESS)]
    public function __invoke(Request $request, string $id): Response
    {
        if (Uuid::isValid($id) === false) {
            throw new PuzzleChangeRequestNotFound();
        }

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $decision = $request->request->getString('decision');

        if (in_array($decision, ['approve', 'reject'], true) === false) {
            throw new BadRequestHttpException('Unknown decision.');
        }

        $player = $this->retrieveLoggedUserProfile->getProfile() ?? throw $this->createAccessDeniedException();
        $isAdmin = $this->isGranted(AdminAccessVoter::ADMIN_ACCESS);
        $changeRequest = $this->getPuzzleChangeRequests->byId($id, includeSecret: $isAdmin) ?? throw new PuzzleChangeRequestNotFound();

        $error = $this->refusal($changeRequest);
        $decided = false;

        if ($error === null) {
            try {
                if ($decision === 'approve') {
                    $this->messageBus->dispatch(new ApprovePuzzleChangeRequest(
                        changeRequestId: $changeRequest->id,
                        puzzleId: $changeRequest->puzzleId,
                        reviewerId: $player->playerId,
                        // The proposed picture only - every other field stays as the puzzle has it now
                        selectedFields: ['image'],
                        decisionSource: MergeDecisionSource::AdminUi,
                        // The card's puzzle - one saved since the grid was loaded refuses the approval
                        recordVersion: $request->request->getString('record_version') !== '' ? $request->request->getString('record_version') : null,
                    ));
                } else {
                    $this->messageBus->dispatch(new RejectPuzzleChangeRequest(
                        changeRequestId: $changeRequest->id,
                        puzzleId: $changeRequest->puzzleId,
                        reviewerId: $player->playerId,
                        rejectionReason: self::rejectionReason($request),
                    ));
                }

                $decided = true;
            } catch (PuzzleChangeRequestAlreadyReviewed) {
                $error = $this->translator->trans('admin.puzzle_change_request.already_reviewed');
            } catch (PuzzleChangedMeanwhile) {
                $error = $this->translator->trans('puzzle_names.record_changed_meanwhile');
            } catch (PuzzleIsStillSecret) {
                $error = $this->translator->trans('competition.reveal.puzzle_still_secret');
            } catch (PuzzleImageChangedMeanwhile) {
                $error = $this->translator->trans('admin.puzzle_change_request.proposal_image_outdated');
            } catch (PuzzleOriginalImageNotArchived) {
                $error = $this->translator->trans('admin.puzzle_change_request.original_not_archived');
            } catch (InvalidPuzzleValues $exception) {
                $error = $exception->getMessage();
            }
        }

        $message = $decided
            ? $this->translator->trans($decision === 'approve' ? 'admin.puzzle_change_request.approved' : 'admin.puzzle_change_request.rejected')
            : $error;

        if ($request->getPreferredFormat() === TurboBundle::STREAM_FORMAT) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);

            // The card as it is now - decided, or still pending with what stopped it. Read again after the decision:
            // PHPStan takes it for the value read above (remembered method call)
            $current = $this->getPuzzleChangeRequests->byId($id, includeSecret: $isAdmin) ?? $changeRequest; // @phpstan-ignore nullCoalesce.expr

            return $this->render('admin/_picture_proposal_card.stream.html.twig', [
                'request' => $current,
                'message' => $message,
                'message_type' => $decided ? 'success' : 'danger',
                'grid' => self::gridState($request),
            ], new Response(status: $decided ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $this->addFlash($decided ? 'success' : 'danger', (string) $message);

        return $this->redirectToRoute('admin_puzzle_change_request_pictures', self::gridState($request), Response::HTTP_SEE_OTHER);
    }

    /**
     * What stops a decision from the grid before anything is dispatched - null when nothing does.
     */
    private function refusal(PuzzleChangeRequestOverview $changeRequest): null|string
    {
        if ($changeRequest->status !== PuzzleReportStatus::Pending) {
            return $this->translator->trans('admin.puzzle_change_request.already_reviewed');
        }

        // Anything but a picture is reviewed on the request's own page
        if ($changeRequest->hasImageChange() === false) {
            return $this->translator->trans('admin.puzzle_change_request.no_picture_proposed');
        }

        // A crop of the picture the puzzle had - approving it would replace the newer one
        if ($changeRequest->pictureChangedSinceProposed()) {
            return $this->translator->trans('admin.puzzle_change_request.proposal_image_outdated');
        }

        return null;
    }

    private static function rejectionReason(Request $request): string
    {
        $reason = trim($request->request->getString('rejection_reason'));

        if ($reason === '') {
            return self::DEFAULT_REJECTION_REASON;
        }

        return mb_substr($reason, 0, self::REJECTION_REASON_MAX_LENGTH);
    }

    /**
     * Where the card was - the grid's filter and page, posted along by the card's forms.
     *
     * @return array{tag: string, source?: string, page?: int}
     */
    private static function gridState(Request $request): array
    {
        $tag = $request->request->getString('tag');
        $state = ['tag' => preg_match('/^[A-Za-z0-9_.:-]{1,50}$/', $tag) === 1 ? $tag : PuzzleChangeRequestPicturesController::DEFAULT_TAG];
        $source = $request->request->getString('source');

        if ($source !== '' && preg_match('/^[a-z_]{1,30}$/', $source) === 1) {
            $state['source'] = $source;
        }

        $page = $request->request->getInt('page');

        if ($page > 1) {
            $state['page'] = $page;
        }

        return $state;
    }
}
