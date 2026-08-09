<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Api\V0;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\PairPlayerWithWjpf;
use SpeedPuzzling\Web\Query\GetPlayerIdByEmail;
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

        if ($wjpfId === null || $email === null) {
            return $this->json([
                'status' => 'error',
                'mensaje' => 'idusuario and email are required',
            ], Response::HTTP_BAD_REQUEST);
        }

        $playerId = $this->getPlayerIdByEmail->byEmail($email);

        if ($playerId === null) {
            $this->logger->info('WJPF pairing request for an unknown e-mail', [
                'wjpf_id' => $wjpfId,
            ]);

            // No MySpeedPuzzlingId key at all: their client writes whatever it finds straight
            // into its database, so an absent key is safer than an empty one.
            return $this->json([
                'status' => 'error',
                'mensaje' => 'player not found',
            ]);
        }

        $this->commandBus->dispatch(
            new PairPlayerWithWjpf(
                playerId: $playerId,
                wjpfId: $wjpfId,
                wjpfNameUrl: $nombreUrl,
                email: $email,
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
        $value = $request->request->get($key) ?? $request->query->get($key);

        if (is_string($value) === false) {
            return is_int($value) ? (string) $value : null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
