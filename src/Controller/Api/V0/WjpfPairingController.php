<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api\V0;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\PairPlayerWithWjpf;
use SpeedPuzzling\Web\Query\GetPlayerIdByEmail;
use SpeedPuzzling\Web\Services\Wjpf\WjpfPairingCodeStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * WJPF-initiated pairing: they send us one of their players' e-mail addresses and we answer
 * with the matching MySpeedPuzzling id, which their side then stores.
 *
 * The response shape mirrors their own conventions (`status` / `mensaje` / `coderror`) because
 * their client branches on those fields, not on HTTP status codes.
 *
 * Their `request_var()` reads GET and POST alike, so every field is accepted from either -
 * including the token, which the legacy `/api/v0/*` endpoints carry in the query string.
 */
final class WjpfPairingController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerIdByEmail $getPlayerIdByEmail,
        readonly private WjpfPairingCodeStore $wjpfPairingCodeStore,
        readonly private MessageBusInterface $commandBus,
        readonly private LoggerInterface $logger,
        #[Autowire(env: 'trim:string:WJPF_API_TOKEN')]
        readonly private string $wjpfApiToken,
    ) {
    }

    #[Route(path: '/api/v0/wjpf-pairing', name: 'api_v0_wjpf_pairing', methods: ['POST', 'GET'])]
    public function __invoke(Request $request): Response
    {
        // Closed-by-default: an unconfigured token must never match an empty submission.
        if ($this->wjpfApiToken === '' || hash_equals($this->wjpfApiToken, $this->param($request, 'token') ?? '') === false) {
            $this->logger->warning('Rejected WJPF pairing request with an invalid token', [
                'client_ip' => $request->getClientIp(),
            ]);

            return $this->json([
                'status' => 'error',
                'coderror' => 151,
                'mensaje' => 'token invalid',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // They send `idusuario` (confirmed 2026-08-09); `idjugador` is accepted as an alias
        // because it is the column name on their side and appeared in an earlier draft.
        $wjpfId = $this->param($request, 'idusuario') ?? $this->param($request, 'idjugador');
        $email = $this->param($request, 'email');
        $nombreUrl = $this->param($request, 'nombreurl');
        $code = $this->param($request, 'code');

        if ($wjpfId === null || ($email === null && $code === null)) {
            return $this->json([
                'status' => 'error',
                'mensaje' => 'idusuario and either code or email are required',
            ], Response::HTTP_BAD_REQUEST);
        }

        // A code is proof the player authorised this in their own browser, so it outranks an
        // address - and it is the only path that works when the two sites hold different ones.
        if ($code !== null) {
            $playerId = $this->wjpfPairingCodeStore->consume($code);
            $source = PairPlayerWithWjpf::SOURCE_PAIRING_CODE;

            if ($playerId === null) {
                $this->logger->warning('WJPF pairing code could not be redeemed', [
                    'wjpf_id' => $wjpfId,
                    'client_ip' => $request->getClientIp(),
                ]);

                return $this->json([
                    'status' => 'error',
                    'mensaje' => 'code invalid or expired',
                ]);
            }
        } else {
            $playerId = $this->getPlayerIdByEmail->byEmail((string) $email);
            $source = PairPlayerWithWjpf::SOURCE_INBOUND;

            if ($playerId === null) {
                $this->logger->info('WJPF pairing request for an unknown e-mail', [
                    'wjpf_id' => $wjpfId,
                ]);

                // No MySpeedPuzzlingId key at all: their client writes whatever it finds
                // straight into its database, so an absent key is safer than an empty one.
                return $this->json([
                    'status' => 'error',
                    'mensaje' => 'player not found',
                ]);
            }
        }

        $this->commandBus->dispatch(
            new PairPlayerWithWjpf(
                playerId: $playerId,
                wjpfId: $wjpfId,
                wjpfNameUrl: $nombreUrl,
                email: $code !== null ? null : $email,
                source: $source,
            ),
        );

        return $this->json([
            'status' => 'ok',
            // Exact casing matters - their client reads $respuesta['MySpeedPuzzlingId'].
            'MySpeedPuzzlingId' => $playerId,
        ]);
    }

    private function param(Request $request, string $key): null|string
    {
        $value = $request->request->get($key)
            ?? $this->bodyParameters($request)[$key]
            ?? $request->query->get($key);

        if (is_string($value) === false) {
            return is_int($value) ? (string) $value : null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Fields recovered from the raw request body, for the cases PHP refuses to put in $_POST.
     *
     * Two real shapes arrive here. A JSON body is decoded as JSON. A form-encoded body sent
     * with a JSON Content-Type - the easy mistake when adding
     * `header('Content-Type: application/json')` to an existing http_build_query() call - is
     * parsed as a query string. Without this the request looks completely empty and the caller
     * gets "token invalid", which points at exactly the wrong thing.
     *
     * @return array<string, mixed>
     */
    private function bodyParameters(Request $request): array
    {
        $body = $request->getContent();

        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        parse_str($body, $parsed);

        /** @var array<string, mixed> $stringKeyed */
        $stringKeyed = array_filter($parsed, static fn (int|string $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);

        return $stringKeyed;
    }
}
