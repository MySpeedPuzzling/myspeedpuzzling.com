<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Wjpf;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\WjpfIdentity;
use SpeedPuzzling\Web\Repository\WjpfIdentityRepository;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;

/**
 * Writes the outcome of a WJPF lookup onto a player, creating the mapping row on first
 * contact. Shared by the outbound sync and the inbound pairing endpoint so both directions
 * produce identical rows and identical warnings.
 */
readonly final class WjpfIdentityRecorder
{
    public function __construct(
        private WjpfIdentityRepository $wjpfIdentityRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public function recordNotFound(Player $player, string $email, array $response): WjpfIdentity
    {
        $now = $this->clock->now();
        $identity = $this->findOrCreate($player, $email, WjpfPairingStatus::NotFound, $now);
        $identity->recordNotFound($email, $response, $now);

        $this->wjpfIdentityRepository->save($identity);

        return $identity;
    }

    /**
     * @param null|string $conflictingMySpeedPuzzlingId Their record's existing id when it is
     *                                                  somebody else's - null when free or ours.
     * @param array<string, mixed> $response
     */
    public function recordPairing(
        Player $player,
        string $email,
        string $wjpfId,
        null|string $wjpfNameUrl,
        null|string $conflictingMySpeedPuzzlingId,
        bool $claimLanded,
        array $response,
    ): WjpfIdentity {
        $now = $this->clock->now();
        $playerId = $player->id->toString();

        if ($conflictingMySpeedPuzzlingId !== null) {
            // We still keep our half of the mapping, but their write guard means this can
            // never be corrected remotely - it needs a human, hence warning level.
            $this->logger->warning('WJPF record is linked to a different MySpeedPuzzling player', [
                'player_id' => $playerId,
                'player_name' => $player->name,
                'checked_email' => $email,
                'wjpf_id' => $wjpfId,
                'wjpf_name_url' => $wjpfNameUrl,
                'their_myspeedpuzzling_id' => $conflictingMySpeedPuzzlingId,
                'response' => $response,
            ]);
        }

        $alreadyHeldBy = $this->wjpfIdentityRepository->findOtherPlayerHoldingWjpfId($wjpfId, $playerId);

        if ($alreadyHeldBy !== null) {
            $this->logger->warning('WJPF id is claimed by more than one MySpeedPuzzling player', [
                'wjpf_id' => $wjpfId,
                'player_id' => $playerId,
                'checked_email' => $email,
                'other_player_id' => $alreadyHeldBy->player->id->toString(),
                'other_checked_email' => $alreadyHeldBy->checkedEmail,
            ]);
        }

        $identity = $this->findOrCreate($player, $email, WjpfPairingStatus::Paired, $now);
        $identity->recordPairing(
            checkedEmail: $email,
            wjpfId: $wjpfId,
            wjpfNameUrl: $wjpfNameUrl,
            conflictingMySpeedPuzzlingId: $conflictingMySpeedPuzzlingId,
            claimLanded: $claimLanded,
            response: $response,
            checkedAt: $now,
        );

        $this->wjpfIdentityRepository->save($identity);

        return $identity;
    }

    private function findOrCreate(
        Player $player,
        string $email,
        WjpfPairingStatus $initialStatus,
        \DateTimeImmutable $now,
    ): WjpfIdentity {
        return $this->wjpfIdentityRepository->findByPlayerId($player->id->toString())
            ?? new WjpfIdentity(
                id: Uuid::uuid7(),
                player: $player,
                checkedEmail: $email,
                status: $initialStatus,
                checkedAt: $now,
            );
    }
}
