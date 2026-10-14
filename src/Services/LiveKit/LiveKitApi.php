<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

use SpeedPuzzling\Web\Exceptions\LiveKitRequestFailed;
use SpeedPuzzling\Web\Value\LiveKitPermission;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * LiveKit's server API (docs/features/online-competitions/contracts/phase-1b.md §2) - Twirp: a JSON POST to
 * `{LIVEKIT_API_URL}/twirp/livekit.RoomService/{Method}` with a token of LiveKitTokens::server() valid for one minute,
 * the protocol's field names in the body. Short timeouts: the calls run inside a request, after the commit of what they
 * follow up on (StudioLiveUpdates) - a slow media server must never hold a page.
 *
 * Every refusal is a LiveKitRequestFailed (not configured, unreachable, or the server's Twirp error code), never a
 * generic exception.
 */
final readonly class LiveKitApi
{
    public const string SERVICE = 'livekit.RoomService';
    public const float TIMEOUT_SECONDS = 2.0;
    public const float MAX_DURATION_SECONDS = 3.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LiveKitSettings $settings,
        private LiveKitTokens $liveKitTokens,
    ) {
    }

    /**
     * Creates the room (or answers the existing one) - LiveKit also creates a room on its first join
     *
     * @return array<string, mixed> the room (the protocol's Room)
     *
     * @throws LiveKitRequestFailed
     */
    public function createRoom(string $room, int $emptyTimeoutSeconds = 0, int $maxParticipants = 0): array
    {
        $body = ['name' => $room];

        if ($emptyTimeoutSeconds > 0) {
            $body['empty_timeout'] = $emptyTimeoutSeconds;
        }

        if ($maxParticipants > 0) {
            $body['max_participants'] = $maxParticipants;
        }

        return $this->call('CreateRoom', $body, ['roomCreate' => true]);
    }

    /**
     * @return list<array<string, mixed>> who is in the room (the protocol's ParticipantInfo each)
     *
     * @throws LiveKitRequestFailed
     */
    public function listParticipants(string $room): array
    {
        $participants = $this->call('ListParticipants', ['room' => $room], self::adminOf($room))['participants'] ?? [];

        if (!is_array($participants)) {
            return [];
        }

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($participants, static fn (mixed $participant): bool => is_array($participant)));

        return $list;
    }

    /**
     * Replaces what somebody connected to the room may do - takes effect at once: tracks they may no longer publish are
     * unpublished
     *
     * @return array<string, mixed> the participant as it is now (the protocol's ParticipantInfo)
     *
     * @throws LiveKitRequestFailed `not_found` when they are not in the room
     */
    public function updateParticipant(string $room, string $identity, LiveKitPermission $permission): array
    {
        return $this->call('UpdateParticipant', [
            'room' => $room,
            'identity' => $identity,
            'permission' => $permission->toProtocol(),
        ], self::adminOf($room));
    }

    /**
     * A data message from the server - to the given identities only (none: everybody in the room), under a topic
     *
     * @param list<string> $destinationIdentities
     *
     * @throws LiveKitRequestFailed
     */
    public function sendData(string $room, string $data, array $destinationIdentities, string $topic, bool $reliable = true): void
    {
        $this->call('SendData', [
            'room' => $room,
            'data' => base64_encode($data),
            'kind' => $reliable ? 'RELIABLE' : 'LOSSY',
            'destination_identities' => $destinationIdentities,
            'topic' => $topic,
        ], self::adminOf($room));
    }

    /**
     * Disconnects somebody from the room - their token stops working for a minute (the server's default)
     *
     * @throws LiveKitRequestFailed `not_found` when they are not in the room
     */
    public function removeParticipant(string $room, string $identity): void
    {
        $this->call('RemoveParticipant', ['room' => $room, 'identity' => $identity], self::adminOf($room));
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, bool|string> $grant
     * @return array<string, mixed>
     *
     * @throws LiveKitRequestFailed
     */
    private function call(string $method, array $body, array $grant): array
    {
        if ($this->settings->isAvailable() === false) {
            throw LiveKitRequestFailed::notConfigured($method);
        }

        try {
            $response = $this->httpClient->request('POST', $this->settings->apiUrl() . '/twirp/' . self::SERVICE . '/' . $method, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->liveKitTokens->server($grant),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw LiveKitRequestFailed::unreachable($method, $e);
        }

        $decoded = json_decode($content, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $code = $decoded['code'] ?? null;
            $reason = $decoded['msg'] ?? null;

            throw LiveKitRequestFailed::refused(
                $method,
                $status,
                is_string($code) && $code !== '' ? $code : 'http_' . $status,
                is_string($reason) ? $reason : '',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @return array{room: string, roomAdmin: true}
     */
    private static function adminOf(string $room): array
    {
        return ['room' => $room, 'roomAdmin' => true];
    }
}
