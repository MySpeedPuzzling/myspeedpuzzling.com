<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;

/**
 * One notice per registered person of a marked time - the tracker and every registered member of a pair/team - for
 * the case's current mark (docs/features/suspicious-time-review.md, "The notice run"). Once per mark: a person with a
 * notice for this marked_at is skipped.
 */
readonly final class SuspiciousTimeNotices
{
    public function __construct(
        private SuspiciousTimeNoticeRepository $noticeRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @return int how many notices were created
     */
    public function tellMembers(SuspiciousTimeCase $case, SuspiciousTimeNoticeVia $via, DateTimeImmutable $now): int
    {
        $markedAt = $case->markedAt;
        assert($markedAt !== null);

        $toldPlayerIds = [];

        foreach ($this->noticeRepository->findOfCase($case) as $notice) {
            if ($notice->isAbout($case)) {
                $toldPlayerIds[$notice->player->id->toString()] = true;
            }
        }

        $created = 0;

        foreach ($case->time->memberPlayerIds() as $playerId) {
            if (isset($toldPlayerIds[$playerId])) {
                continue;
            }

            try {
                $player = $this->playerRepository->get($playerId);
            } catch (PlayerNotFound) {
                // A stale member of the group snapshot - nobody to tell
                continue;
            }

            $this->noticeRepository->save(new SuspiciousTimeNotice(
                id: Uuid::uuid7(),
                case: $case,
                player: $player,
                markedAt: $markedAt,
                notifiedAt: $now,
                via: $via,
            ));
            $toldPlayerIds[$playerId] = true;
            $created++;
        }

        return $created;
    }
}
