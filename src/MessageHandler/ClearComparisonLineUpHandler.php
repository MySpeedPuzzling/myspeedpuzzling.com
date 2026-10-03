<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ClearComparisonLineUp;
use SpeedPuzzling\Web\Repository\ComparisonSubjectRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\ComparisonKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Empties one line-up of the owner (docs/features/player-comparison.md): every row of that kind goes, except the
 * owner's own Solo row - members and free players alike, clearing means "nobody to compare with", not "not even me".
 * The other kinds stay untouched.
 */
#[AsMessageHandler]
readonly final class ClearComparisonLineUpHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ComparisonSubjectRepository $comparisonSubjectRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(ClearComparisonLineUp $message): void
    {
        $owner = $this->playerRepository->get($message->playerId);

        foreach ($this->comparisonSubjectRepository->listByOwner($owner) as $row) {
            if ($row->kind() !== $message->kind) {
                continue;
            }

            if ($message->kind === ComparisonKind::Solo && $row->isSelf()) {
                continue;
            }

            $this->comparisonSubjectRepository->delete($row);
        }
    }
}
