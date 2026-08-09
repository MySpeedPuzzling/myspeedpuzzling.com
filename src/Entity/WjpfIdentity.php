<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\Table;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\WjpfPairingStatus;

/**
 * The mapping between a MySpeedPuzzling player and their account in the World Jigsaw
 * Puzzle Federation (worldjigsawpuzzle.org) player database - one row per player.
 *
 * Negative outcomes are rows too: a `not_found` row records that the address was checked
 * and matched nothing, which is what lets a bulk re-run skip everyone already seen.
 *
 * `wjpf_id` is deliberately NOT unique. Two players claiming the same IdJugador is real
 * data about a real problem, and a unique constraint would abort a multi-hour backfill
 * over it. Duplicates are logged at warning level instead.
 */
#[Entity]
#[Table(name: 'wjpf_identity')]
#[Index(columns: ['wjpf_id'])]
#[Index(columns: ['status'])]
class WjpfIdentity
{
    /** Their `IdJugador` - numeric in their database, string over the wire. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 64, nullable: true)]
    public null|string $wjpfId = null;

    /** Their `NombreURL` - the slug of the player's profile page on their site. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 255, nullable: true)]
    public null|string $wjpfNameUrl = null;

    /**
     * Set only for {@see WjpfPairingStatus::Conflict}: the foreign MySpeedPuzzling id their
     * record points at. Stored as a plain string - we cannot assume their column holds a
     * well-formed UUID.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 255, nullable: true)]
    public null|string $conflictingMySpeedPuzzlingId = null;

    /** First time we saw their record linked to this player. */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pairedAt = null;

    /**
     * When we sent our id *and* their column was still empty, i.e. when our write actually
     * landed on their side. Their endpoint echoes the row before it updates, so a response
     * can never confirm its own write - an empty pre-call value is the only evidence.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $claimedAt = null;

    /**
     * Last decoded response, verbatim. Their payload is small and may grow fields we do not
     * model yet; keeping it costs nothing and answers "what did they actually say?" later.
     *
     * @var array<string, mixed>
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::JSON, options: ['default' => '{}'])]
    public array $lastResponse = [];

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // DB-level cascade so a GDPR player deletion takes the mapping with it
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        readonly public Player $player,
        /** The address we matched on, kept for support - it may since have changed. */
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 320)]
        public string $checkedEmail,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, enumType: WjpfPairingStatus::class)]
        public WjpfPairingStatus $status,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $checkedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public function recordNotFound(string $checkedEmail, array $response, DateTimeImmutable $checkedAt): void
    {
        $this->checkedEmail = $checkedEmail;
        $this->status = WjpfPairingStatus::NotFound;
        $this->checkedAt = $checkedAt;
        $this->lastResponse = $response;

        // wjpfId / pairedAt are deliberately left alone: a player who disappears from their
        // database (or changes the address there) should not silently lose an earlier match.
    }

    /**
     * @param array<string, mixed> $response
     */
    public function recordPairing(
        string $checkedEmail,
        string $wjpfId,
        null|string $wjpfNameUrl,
        null|string $conflictingMySpeedPuzzlingId,
        bool $claimLanded,
        array $response,
        DateTimeImmutable $checkedAt,
    ): void {
        $this->checkedEmail = $checkedEmail;
        $this->wjpfId = $wjpfId;
        $this->status = $conflictingMySpeedPuzzlingId === null
            ? WjpfPairingStatus::Paired
            : WjpfPairingStatus::Conflict;
        $this->conflictingMySpeedPuzzlingId = $conflictingMySpeedPuzzlingId;
        $this->checkedAt = $checkedAt;
        $this->lastResponse = $response;

        if ($wjpfNameUrl !== null) {
            $this->wjpfNameUrl = $wjpfNameUrl;
        }

        $this->pairedAt ??= $checkedAt;

        if ($claimLanded) {
            $this->claimedAt = $checkedAt;
        }
    }
}
