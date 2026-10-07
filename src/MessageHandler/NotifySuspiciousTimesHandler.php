<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SuspiciousTimeNotice;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\NotifySuspiciousTimes;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Value\SuspiciousTimeNoticeVia;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The notice run (docs/features/suspicious-time-review.md, "The notice run"): every mark in force - marked in the
 * queue or flagged by SQL (the scan's reconciliation gave those a case) - gets one notice per registered person of the
 * time, the tracker and every registered member of a pair/team. Once per mark: a person with a notice for this
 * marked_at is skipped, an unmark followed by a new mark is told again.
 *
 * The run at go-live (toldByHand, `--existing-marks-told-by-hand`) records every notice it creates as already sent
 * (manual_email) - those marks were e-mailed by hand, so the banner and the e-mail never mention them; a later new
 * mark of such a time is told like any other.
 */
#[AsMessageHandler]
readonly final class NotifySuspiciousTimesHandler
{
    public function __construct(
        private SuspiciousTimeCaseRepository $caseRepository,
        private SuspiciousTimeNoticeRepository $noticeRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(NotifySuspiciousTimes $message): int
    {
        $now = $this->clock->now();
        $created = 0;

        foreach ($this->caseRepository->findMarkedOfFlaggedTimes() as $case) {
            $markedAt = $case->markedAt;
            assert($markedAt !== null);

            $noticesByPlayer = [];

            foreach ($this->noticeRepository->findOfCase($case) as $notice) {
                $noticesByPlayer[$notice->player->id->toString()][] = $notice;
            }

            foreach ($case->time->memberPlayerIds() as $playerId) {
                $notices = $noticesByPlayer[$playerId] ?? [];

                foreach ($notices as $notice) {
                    if ($notice->isAbout($case)) {
                        continue 2;
                    }
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
                    via: $message->toldByHand ? SuspiciousTimeNoticeVia::ManualEmail : SuspiciousTimeNoticeVia::Run,
                ));
                $created++;
            }
        }

        return $created;
    }
}
