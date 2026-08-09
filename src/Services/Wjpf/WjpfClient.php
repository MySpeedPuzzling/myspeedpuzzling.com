<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Wjpf;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\WjpfRequestFailed;
use SpeedPuzzling\Web\Value\WjpfUser;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the WJPF player lookup at worldjigsawpuzzle.org.
 *
 * One call does both halves of the pairing: it returns their IdJugador/NombreURL for us to
 * store, and - when `myspeedpuzzlingid` is sent - their side writes our id into its own
 * `Jugadores` row, but *only if that column is still empty*. That write is one-way and
 * permanent: once their column holds any value, nothing we send can change it.
 *
 * Closed-by-default: with an empty WJPF_API_TOKEN, isEnabled() returns false and callers
 * must skip their work.
 */
readonly final class WjpfClient
{
    private const string ACTION = 'wjpf_user';

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $wjpfApiUrl,
        private string $wjpfApiToken,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->wjpfApiUrl !== '' && $this->wjpfApiToken !== '';
    }

    /**
     * @param null|string $claimMySpeedPuzzlingId Our player id to write into their database.
     *                                            Pass null for a read-only lookup: their
     *                                            conditional UPDATE then writes '' over an
     *                                            already-empty column, i.e. changes nothing.
     *
     * @return null|WjpfUser Null when their database has no row for this address.
     *
     * @throws WjpfRequestFailed
     */
    public function findUserByEmail(string $email, null|string $claimMySpeedPuzzlingId = null): null|WjpfUser
    {
        $body = [
            'accion' => self::ACTION,
            'token' => $this->wjpfApiToken,
            'email' => $email,
        ];

        if ($claimMySpeedPuzzlingId !== null) {
            $body['myspeedpuzzlingid'] = $claimMySpeedPuzzlingId;
        }

        try {
            $response = $this->client->request('POST', $this->wjpfApiUrl, [
                // `accion` is duplicated into the query string because their published
                // examples pass it there; their request_var() reads both, and sending it
                // twice is harmless.
                'query' => ['accion' => self::ACTION],
                'body' => $body,
                'timeout' => 5,
                'max_duration' => 10,
            ]);

            // getContent(false) so a non-2xx body reaches the decoder instead of throwing -
            // their endpoint answers 200 for everything, so a non-2xx means their host is
            // in trouble and the body is worth logging.
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw WjpfRequestFailed::transportError($e->getMessage(), $e);
        }

        if ($statusCode >= 400) {
            throw WjpfRequestFailed::transportError(sprintf('HTTP %d - %s', $statusCode, $content));
        }

        $data = $this->decode($content);
        $status = $this->stringOrNull($data, 'status');

        if ($status !== 'ok') {
            // Their failures carry a `coderror` (151 = token invalid); a plain
            // {"status":"error","mensaje":"player not found"} has none. That is the
            // difference between "we are misconfigured" and "this person is not a member".
            $errorCode = $data['coderror'] ?? null;

            if (is_int($errorCode) || (is_string($errorCode) && $errorCode !== '')) {
                throw WjpfRequestFailed::remoteError($this->stringOrNull($data, 'mensaje') ?? '', $errorCode);
            }

            return null;
        }

        $idJugador = trim($this->stringOrNull($data, 'IdJugador') ?? '');

        if ($idJugador === '') {
            throw WjpfRequestFailed::missingPlayerId($content);
        }

        return new WjpfUser(
            idJugador: $idJugador,
            nombreUrl: $this->stringOrNull($data, 'NombreURL'),
            mySpeedPuzzlingId: $this->stringOrNull($data, 'MySpeedPuzzlingId'),
            raw: $data,
        );
    }

    /**
     * Their handler runs `echo $BDwjpf->error;` around the JSON, so a database hiccup on
     * their side arrives as JSON with text glued to it. Strict decoding first; on failure,
     * one salvage attempt at the outermost braces before giving up.
     *
     * @return array<string, mixed>
     *
     * @throws WjpfRequestFailed
     */
    private function decode(string $content): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $salvaged = json_decode(substr($content, $start, $end - $start + 1), true);

            if (is_array($salvaged)) {
                $this->logger->warning('WJPF response had text around the JSON payload', [
                    'body' => $content,
                ]);

                /** @var array<string, mixed> $salvaged */
                return $salvaged;
            }
        }

        throw WjpfRequestFailed::unreadableResponse($content);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function stringOrNull(array $data, string $key): null|string
    {
        $value = $data[$key] ?? null;

        if (is_string($value)) {
            return $value;
        }

        // Their JSON is generated straight from MySQL rows, so an id can arrive as a number
        return is_int($value) ? (string) $value : null;
    }
}
