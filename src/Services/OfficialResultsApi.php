<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The shared rules of the official results JSON endpoints (docs/features/competitions-management/official-results.md):
 * every answer is JSON and `private, no-store`; nobody is ever redirected to the login page (a device's outbox must
 * see "sign in again", not a 200 login page) - 401 `sign_in_required` instead; 403 `forbidden` without
 * COMPETITION_EDIT on the competition (the round state and result changes ask COMPETITION_RESULTS_ENTRY, which
 * referees have too); writes need `Content-Type: application/json` (415) and the stateless CSRF token
 * in the `X-CSRF-Token` header (403 `invalid_csrf_token`) - the page renders `csrf_token('official_results')` into a
 * data attribute.
 */
final readonly class OfficialResultsApi
{
    public const string CSRF_TOKEN_ID = 'official_results';
    public const string CSRF_HEADER = 'X-CSRF-Token';

    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private AuthorizationCheckerInterface $authorizationChecker,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    /**
     * @return string|JsonResponse the acting player's id, or the error to answer with
     */
    public function authorise(
        Request $request,
        string $competitionId,
        bool $write,
        string $attribute = CompetitionEditVoter::COMPETITION_EDIT,
    ): string|JsonResponse {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return self::error('sign_in_required', JsonResponse::HTTP_UNAUTHORIZED);
        }

        if ($this->authorizationChecker->isGranted($attribute, $competitionId) === false) {
            return self::error('forbidden', JsonResponse::HTTP_FORBIDDEN);
        }

        if ($write === false) {
            return $profile->playerId;
        }

        if ($request->getContentTypeFormat() !== 'json') {
            return self::error('json_required', JsonResponse::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->headers->get(self::CSRF_HEADER, ''));

        if ($this->csrfTokenManager->isTokenValid($token) === false) {
            return self::error('invalid_csrf_token', JsonResponse::HTTP_FORBIDDEN);
        }

        return $profile->playerId;
    }

    /**
     * The request's JSON object, or the 400 to answer with.
     *
     * @return array<string, mixed>|JsonResponse
     */
    public static function body(Request $request): array|JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            return self::error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    public static function json(mixed $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $error, int $status, array $details = []): JsonResponse
    {
        return self::json(['error' => $error, ...$details], $status);
    }
}
