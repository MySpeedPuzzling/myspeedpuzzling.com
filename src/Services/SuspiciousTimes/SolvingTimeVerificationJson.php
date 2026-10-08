<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use DateTimeInterface;
use SpeedPuzzling\Web\Repository\PuzzleSolvingTimeRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeCaseRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeNoticeRepository;
use SpeedPuzzling\Web\Value\SuspiciousTimeReason;

/**
 * A solving time's verification state as the internal API answers it (docs/features/internal-api.md, "Time
 * verification"): the flag, the time, its case and the notices of the case's current mark.
 */
readonly final class SolvingTimeVerificationJson
{
    public function __construct(
        private PuzzleSolvingTimeRepository $puzzleSolvingTimeRepository,
        private SuspiciousTimeCaseRepository $suspiciousTimeCaseRepository,
        private SuspiciousTimeNoticeRepository $suspiciousTimeNoticeRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(string $timeId): array
    {
        $time = $this->puzzleSolvingTimeRepository->get($timeId);
        $case = $this->suspiciousTimeCaseRepository->findByTime($time->id->toString());

        $notices = [];

        if ($case !== null && $case->isMarked()) {
            foreach ($this->suspiciousTimeNoticeRepository->findOfCase($case) as $notice) {
                if ($notice->isAbout($case)) {
                    $notices[] = [
                        'playerId' => $notice->player->id->toString(),
                        'via' => $notice->via->value,
                        'notifiedAt' => $notice->notifiedAt->format(DateTimeInterface::ATOM),
                        'response' => $notice->response?->value,
                        'responseText' => $notice->responseText,
                        'answer' => $notice->answer?->value,
                    ];
                }
            }
        }

        return [
            'timeId' => $time->id->toString(),
            'suspicious' => $time->suspicious,
            'playerId' => $time->player->id->toString(),
            'puzzleId' => $time->puzzle->id->toString(),
            'puzzleName' => $time->puzzle->name,
            'piecesCount' => $time->puzzle->piecesCount,
            'seconds' => $time->secondsToSolve,
            'puzzlingType' => $time->puzzlingType->value,
            'case' => $case === null ? null : [
                'id' => $case->id->toString(),
                'status' => $case->status->value,
                'origin' => $case->origin->value,
                'direction' => $case->direction?->value,
                'reasons' => self::codes($case->reasons()),
                'reasonsShown' => self::codes($case->reasonsShown()),
                'moderatorNote' => $case->moderatorNote,
                'markedAt' => $case->markedAt?->format(DateTimeInterface::ATOM),
                'decidedAt' => $case->decidedAt?->format(DateTimeInterface::ATOM),
            ],
            'notices' => $notices,
        ];
    }

    /**
     * @param list<SuspiciousTimeReason> $reasons
     * @return list<string>
     */
    private static function codes(array $reasons): array
    {
        return array_map(static fn (SuspiciousTimeReason $reason): string => $reason->code->value, $reasons);
    }
}
