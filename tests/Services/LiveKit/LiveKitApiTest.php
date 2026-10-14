<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\LiveKit;

use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\LiveKitRequestFailed;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitApi;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitTokens;
use SpeedPuzzling\Web\Value\LiveKitPermission;
use SpeedPuzzling\Web\Value\LiveKitTrackSource;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * LiveKit's server API as LiveKitApi calls it (contract phase-1b.md §2): Twirp - a JSON POST per method with the
 * protocol's field names, a one-minute token naming exactly the room it administers - and every refusal a typed
 * LiveKitRequestFailed.
 */
final class LiveKitApiTest extends TestCase
{
    private const string KEY = 'test-key';
    private const string SECRET = 'a-test-secret-that-is-long-enough-for-hs256';
    private const int NOW = 1792000000;
    private const string HALL = 'round-018d0050-0000-0000-0000-000000000101';
    private const string STUDIO = 'studio-018d0050-0000-0000-0000-000000000101';
    private const string OLIVIA = 'p:018d0050-0000-0000-0000-000000000201';

    /** @var list<array{method: string, url: string, headers: array<string, list<string>>, body: string}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    public function testTakingAGuestsPublishingAway(): void
    {
        $this->responses[] = self::json(['participant' => ['identity' => self::OLIVIA, 'permission' => ['canSubscribe' => true]]]);

        $participant = $this->api()->updateParticipant(self::STUDIO, self::OLIVIA, LiveKitPermission::subscribeOnly());

        self::assertSame(['identity' => self::OLIVIA, 'permission' => ['canSubscribe' => true]], $participant['participant']);
        $request = $this->onlyRequest('UpdateParticipant');
        self::assertSame([
            'room' => self::STUDIO,
            'identity' => self::OLIVIA,
            'permission' => [
                'can_subscribe' => true,
                'can_publish' => false,
                'can_publish_data' => false,
                'can_publish_sources' => [],
                'hidden' => false,
            ],
        ], self::body($request));
        self::assertSame(['room' => self::STUDIO, 'roomAdmin' => true], self::serverGrant($request));
    }

    public function testThePermissionsSourcesInTheProtocolsNames(): void
    {
        $this->api()->updateParticipant(self::STUDIO, self::OLIVIA, new LiveKitPermission(
            canSubscribe: true,
            canPublish: true,
            canPublishData: false,
            canPublishSources: [LiveKitTrackSource::Camera, LiveKitTrackSource::Microphone, LiveKitTrackSource::ScreenShare],
        ));

        $permission = self::body($this->onlyRequest('UpdateParticipant'))['permission'] ?? null;
        self::assertIsArray($permission);
        self::assertSame(['CAMERA', 'MICROPHONE', 'SCREEN_SHARE'], $permission['can_publish_sources']);
    }

    public function testAMessageToOneParticipantOfTheHall(): void
    {
        $this->api()->sendData(self::HALL, '{"inviteId":"018d0060-0000-7000-8000-000000000001"}', [self::OLIVIA], 'studio');

        $request = $this->onlyRequest('SendData');
        self::assertSame([
            'room' => self::HALL,
            'data' => base64_encode('{"inviteId":"018d0060-0000-7000-8000-000000000001"}'),
            'kind' => 'RELIABLE',
            'destination_identities' => [self::OLIVIA],
            'topic' => 'studio',
        ], self::body($request));
        self::assertSame(['room' => self::HALL, 'roomAdmin' => true], self::serverGrant($request));

        $this->requests = [];
        $this->api()->sendData(self::HALL, 'ping', [], 'console', reliable: false);
        self::assertSame('LOSSY', self::body($this->onlyRequest('SendData'))['kind']);
    }

    public function testCreatingARoomListingAndRemovingParticipants(): void
    {
        $this->responses[] = self::json(['name' => self::STUDIO, 'sid' => 'RM_1']);
        $this->responses[] = self::json(['participants' => [['identity' => self::OLIVIA], 'not a participant', ['identity' => 'm:018d0000-0000-0000-0000-000000000003']]]);
        $this->responses[] = self::json([]);

        self::assertSame('RM_1', $this->api()->createRoom(self::STUDIO, emptyTimeoutSeconds: 600, maxParticipants: 500)['sid']);
        self::assertSame([['identity' => self::OLIVIA], ['identity' => 'm:018d0000-0000-0000-0000-000000000003']], $this->api()->listParticipants(self::STUDIO));
        $this->api()->removeParticipant(self::HALL, self::OLIVIA);

        self::assertSame(['CreateRoom', 'ListParticipants', 'RemoveParticipant'], array_map(static fn (array $request): string => basename($request['url']), $this->requests));
        self::assertSame(['name' => self::STUDIO, 'empty_timeout' => 600, 'max_participants' => 500], self::body($this->requests[0]));
        self::assertSame(['roomCreate' => true], self::serverGrant($this->requests[0]));
        self::assertSame(['room' => self::STUDIO], self::body($this->requests[1]));
        self::assertSame(['room' => self::HALL, 'identity' => self::OLIVIA], self::body($this->requests[2]));
        self::assertSame(['room' => self::HALL, 'roomAdmin' => true], self::serverGrant($this->requests[2]));
    }

    public function testTheServersRefusalIsTyped(): void
    {
        $this->responses[] = self::json(['code' => 'not_found', 'msg' => 'participant not found'], 404);

        try {
            $this->api()->updateParticipant(self::STUDIO, self::OLIVIA, LiveKitPermission::subscribeOnly());
            self::fail('LiveKit refused');
        } catch (LiveKitRequestFailed $failed) {
            self::assertTrue($failed->isNotFound());
            self::assertSame(404, $failed->httpStatus);
            self::assertSame('UpdateParticipant', $failed->method);
            self::assertStringContainsString('participant not found', $failed->getMessage());
        }

        $this->responses[] = new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]);

        try {
            $this->api()->sendData(self::HALL, 'x', [], 'studio');
            self::fail('A proxy refused');
        } catch (LiveKitRequestFailed $failed) {
            self::assertSame('http_502', $failed->twirpCode);
            self::assertFalse($failed->isNotFound());
        }
    }

    public function testAServerThatCannotBeReachedIsTyped(): void
    {
        $this->responses[] = new MockResponse('', ['error' => 'Could not resolve host: livekit']);

        $this->expectException(LiveKitRequestFailed::class);
        $this->expectExceptionMessage('could not be reached');
        $this->api()->removeParticipant(self::HALL, self::OLIVIA);
    }

    public function testWithoutAServerNothingIsSent(): void
    {
        $api = new LiveKitApi(
            $this->client(),
            $settings = new LiveKitSettings('', 'wss://live.example.test', self::KEY, self::SECRET),
            new LiveKitTokens($settings, new MockClock(new DateTimeImmutable('@' . self::NOW))),
        );

        try {
            $api->sendData(self::HALL, 'x', [], 'studio');
            self::fail('Not configured');
        } catch (LiveKitRequestFailed $failed) {
            self::assertSame(LiveKitRequestFailed::NOT_CONFIGURED, $failed->twirpCode);
        }

        self::assertSame([], $this->requests);
    }

    private function api(): LiveKitApi
    {
        $settings = new LiveKitSettings('http://livekit:7880/', 'wss://live.example.test', self::KEY, self::SECRET);

        return new LiveKitApi($this->client(), $settings, new LiveKitTokens($settings, new MockClock(new DateTimeImmutable('@' . self::NOW))));
    }

    private function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = $options['normalized_headers'] ?? [];
            $body = $options['body'] ?? '';

            /** @var array<string, list<string>> $headers */
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => is_string($body) ? $body : ''];

            return array_shift($this->responses) ?? self::json([]);
        });
    }

    /**
     * @return array{method: string, url: string, headers: array<string, list<string>>, body: string}
     */
    private function onlyRequest(string $twirpMethod): array
    {
        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('http://livekit:7880/twirp/livekit.RoomService/' . $twirpMethod, $request['url']);
        self::assertSame(['Content-Type: application/json'], $request['headers']['content-type'] ?? null);

        return $request;
    }

    /**
     * @param array{method: string, url: string, headers: array<string, list<string>>, body: string} $request
     * @return array<string, mixed>
     */
    private static function body(array $request): array
    {
        $body = json_decode($request['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * The video grant of the call's own token - valid one minute, issued by the key
     *
     * @param array{method: string, url: string, headers: array<string, list<string>>, body: string} $request
     * @return array<string, mixed>
     */
    private static function serverGrant(array $request): array
    {
        $authorization = $request['headers']['authorization'][0] ?? '';
        self::assertStringStartsWith('Authorization: Bearer ', $authorization);

        $previous = JWT::$timestamp;
        JWT::$timestamp = self::NOW;

        try {
            $claims = JWT::decode(substr($authorization, strlen('Authorization: Bearer ')), new Key(self::SECRET, 'HS256'));
        } finally {
            JWT::$timestamp = $previous;
        }

        self::assertSame(self::KEY, $claims->iss);
        self::assertSame(self::NOW + 60, $claims->exp);

        $grant = json_decode((string) json_encode($claims->video), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($grant);

        /** @var array<string, mixed> $grant */
        return $grant;
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function json(array $json, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($json === [] ? new \stdClass() : $json, JSON_THROW_ON_ERROR), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
