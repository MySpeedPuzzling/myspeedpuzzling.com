<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantAlreadyConnectedToDifferentPlayer;
use SpeedPuzzling\Web\Message\ConnectCompetitionParticipant;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineConnectRules;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\OnlineConnectStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A signed-in player on a personal link: connect them to its participant when that is unambiguous
 * (docs/features/online-competitions/README.md §5.4, OnlineConnectRules) - the page POSTs it (a GET never writes:
 * mail scanners and prefetchers open links). Answers `{"status": "connected" | "already_connected" |
 * "belongs_to_other" | "is_other_participant" | "organiser_confirm_required", "serverNowMs": …}`; `confirm=1` (form
 * field, JSON or query) is an organiser's explicit "This is me". Connecting is the existing
 * ConnectCompetitionParticipant - the connection that carries the official result to the player's profile.
 *
 * On the `main` firewall (the signed-in player), refusals as JSON: 401 `sign_in_required`, 403 `invalid_csrf_token`
 * (stateless token id `compete_connect`, as the `X-CSRF-Token` header or a `_token` field), 404 `unknown_link`.
 */
final class CompeteConnectController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'compete_connect';
    public const string CSRF_HEADER = 'X-CSRF-Token';

    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly OnlineConnectRules $onlineConnectRules,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/compete/{token}/connect',
        name: 'compete_connect',
        requirements: ['_locale' => 'en|cs|es|ja|fr|de'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->api->error('sign_in_required', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $fields = self::fields($request);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, self::csrfToken($request, $fields)) === false) {
            return $this->api->error('invalid_csrf_token', JsonResponse::HTTP_FORBIDDEN);
        }

        $access = $this->api->access($token);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $participant = $access->participant;
        $status = $this->onlineConnectRules->decide($participant, $profile->playerId, self::confirmed($request, $fields));

        if ($status === OnlineConnectStatus::Connected) {
            try {
                $this->messageBus->dispatch(new ConnectCompetitionParticipant(
                    competitionId: $access->competition->id->toString(),
                    playerId: $profile->playerId,
                    participantId: $participant->id->toString(),
                ));
            } catch (CompetitionParticipantAlreadyConnectedToDifferentPlayer) {
                // Somebody else's connect came first
                $status = OnlineConnectStatus::BelongsToOther;
            }
        }

        return $this->api->json(['status' => $status->value]);
    }

    /**
     * The posted fields - a form, or a JSON object
     *
     * @return array<mixed>
     */
    private static function fields(Request $request): array
    {
        if ($request->getContentTypeFormat() === 'json') {
            $decoded = json_decode($request->getContent(), true);

            return is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    /**
     * @param array<mixed> $fields
     */
    private static function csrfToken(Request $request, array $fields): string
    {
        $header = $request->headers->get(self::CSRF_HEADER);

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $field = $fields['_token'] ?? null;

        return is_string($field) ? $field : '';
    }

    /**
     * @param array<mixed> $fields
     */
    private static function confirmed(Request $request, array $fields): bool
    {
        $confirm = $fields['confirm'] ?? $request->query->get('confirm');

        return in_array($confirm, [true, 1, '1', 'true'], true);
    }
}
