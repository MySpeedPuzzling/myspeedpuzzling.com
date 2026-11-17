<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Results\TimeProposalAnswer;
use SpeedPuzzling\Web\Results\TimeProposalView;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The rules of the competitor's time proposal endpoints (competition toolkit P1, ProposeRoundTimeController /
 * WithdrawTimeProposalController) - the official results API's conventions (OfficialResultsApi) for a player who is no
 * organiser: JSON answers, `private, no-store`; 401 `sign_in_required` (never a redirect to the login page - the
 * competitor page's outbox resends); `Content-Type: application/json` (415); the stateless CSRF token
 * `csrf_token('time_proposal')` in the `X-CSRF-Token` header (403 `invalid_csrf_token`). Who may propose for which entry
 * is the handler's (TimeProposalOwnership) - nothing here reads the competition.
 *
 * Answers: `{status, reason, message, proposal}` - `proposal` is the competitor's view (TimeProposalView::forCompetitor())
 * of the proposal written or found, `message` the translated reason (time_proposal.reason.*).
 */
final readonly class TimeProposalApi
{
    public const string CSRF_TOKEN_ID = 'time_proposal';

    // HTTP status of a refusal - 200 for everything the phone should simply show
    private const array REFUSAL_STATUS = [
        'proposals_off' => JsonResponse::HTTP_NOT_FOUND,
        'round_not_found' => JsonResponse::HTTP_NOT_FOUND,
        'entry_not_in_round' => JsonResponse::HTTP_NOT_FOUND,
        'proposal_not_found' => JsonResponse::HTTP_NOT_FOUND,
        'not_your_entry' => JsonResponse::HTTP_FORBIDDEN,
        'linked_after_start' => JsonResponse::HTTP_FORBIDDEN,
        'invalid_proposal_id' => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
        'clock_ahead' => JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
        'not_running' => JsonResponse::HTTP_CONFLICT,
        'times_up' => JsonResponse::HTTP_CONFLICT,
        'too_late' => JsonResponse::HTTP_CONFLICT,
        'result_recorded' => JsonResponse::HTTP_CONFLICT,
        'already_proposed' => JsonResponse::HTTP_CONFLICT,
    ];

    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return string|JsonResponse the signed-in player's id, or the error to answer with
     */
    public function authorise(Request $request): string|JsonResponse
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->refusal('sign_in_required', JsonResponse::HTTP_UNAUTHORIZED);
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return OfficialResultsApi::error('json_required', JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get(OfficialResultsApi::CSRF_HEADER, ''));

        if ($this->csrfTokenManager->isTokenValid($token) === false) {
            return OfficialResultsApi::error('invalid_csrf_token', JsonResponse::HTTP_FORBIDDEN);
        }

        return $profile->playerId;
    }

    public function answer(TimeProposalAnswer $answer, null|TimeProposalView $proposal): JsonResponse
    {
        $status = $answer->reason !== null ? (self::REFUSAL_STATUS[$answer->reason] ?? JsonResponse::HTTP_CONFLICT) : JsonResponse::HTTP_OK;

        return OfficialResultsApi::json([
            'status' => $answer->status,
            'reason' => $answer->reason,
            'message' => $answer->reason !== null ? $this->translator->trans('time_proposal.reason.' . $answer->reason) : null,
            'proposal' => $proposal?->forCompetitor(),
        ], $status);
    }

    public function refusal(string $reason, int $status): JsonResponse
    {
        return OfficialResultsApi::error($reason, $status, [
            'message' => $this->translator->trans('time_proposal.reason.' . $reason),
        ]);
    }
}
