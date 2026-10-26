<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\BorrowRequest;
use SpeedPuzzling\Web\Entity\BorrowRequestItem;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\BorrowRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\BorrowRequestTooLarge;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Message\SendBorrowRequestEmail;
use SpeedPuzzling\Web\Query\GetBorrowCandidates;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Results\BorrowCandidate;
use SpeedPuzzling\Web\Value\BorrowAskOutcome;
use SpeedPuzzling\Web\Value\BorrowAskSkipReason;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "Could I borrow these?" (docs/features/wishlist-availability.md). Everything is checked here, whatever the sheet
 * showed: each puzzle is asked of an owner only while GetBorrowCandidates still lists them for it (a favorite, a public
 * collection, no block either way, the allow list for a private owner, never a secret puzzle), it is not lent out, and
 * the asker is not already waiting for it at that owner. The rest is skipped with a reason - every failure of the first
 * rule is "unavailable", so a block can never be told apart from a puzzle that left the collection. A puzzle is asked of
 * one owner only (the first it is assigned to).
 *
 * A resent form (the same send id) finds what it created and creates nothing. The rate limit counts sends, not
 * requests.
 */
#[AsMessageHandler]
readonly final class AskToBorrowPuzzlesHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private BorrowRequestRepository $borrowRequestRepository,
        private NotificationRepository $notificationRepository,
        private GetBorrowCandidates $getBorrowCandidates,
        private RateLimiterFactoryInterface $borrowRequestLimiter,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     * @throws BorrowRequestTooLarge
     * @throws BorrowRequestLimitReached
     */
    public function __invoke(AskToBorrowPuzzles $message): BorrowAskOutcome
    {
        $asker = $this->playerRepository->get($message->askerId);
        $askerId = $asker->id->toString();

        $puzzlesByOwner = $this->normalized($message->puzzlesByOwner);
        $puzzleCount = array_sum(array_map(count(...), $puzzlesByOwner));

        if (count($puzzlesByOwner) > AskToBorrowPuzzles::MAX_OWNERS || $puzzleCount > AskToBorrowPuzzles::MAX_PUZZLES) {
            throw new BorrowRequestTooLarge();
        }

        $alreadySent = $this->borrowRequestRepository->findBySend($askerId, $message->sendId);

        if ($alreadySent !== []) {
            $items = array_sum(array_map(fn (BorrowRequest $request): int => count($this->borrowRequestRepository->itemsOf($request)), $alreadySent));

            return new BorrowAskOutcome(requestsSent: count($alreadySent), puzzlesAsked: $items);
        }

        if ($puzzlesByOwner === []) {
            return new BorrowAskOutcome(requestsSent: 0, puzzlesAsked: 0);
        }

        if ($this->borrowRequestLimiter->create($askerId)->consume()->isAccepted() === false) {
            throw new BorrowRequestLimitReached();
        }

        $candidates = $this->candidatesByPuzzleAndOwner(
            $this->getBorrowCandidates->forPuzzles($askerId, array_merge(...array_values($puzzlesByOwner))),
        );

        $waiting = [];

        foreach ($this->borrowRequestRepository->waitingItemsOf($askerId, array_keys($puzzlesByOwner)) as $item) {
            $waiting[$item->owner->id->toString() . '|' . $item->puzzle->id->toString()] = true;
        }

        $now = $this->clock->now();
        $skipped = [];
        $requestsSent = 0;
        $puzzlesAsked = 0;

        foreach ($puzzlesByOwner as $ownerId => $puzzleIds) {
            $asked = [];

            foreach ($puzzleIds as $puzzleId) {
                $candidate = $candidates[$puzzleId][$ownerId] ?? null;

                if ($candidate === null) {
                    $skipped[$puzzleId] = BorrowAskSkipReason::Unavailable;
                } elseif ($candidate->lentOut) {
                    $skipped[$puzzleId] = BorrowAskSkipReason::LentOut;
                } elseif (isset($waiting[$ownerId . '|' . $puzzleId])) {
                    $skipped[$puzzleId] = BorrowAskSkipReason::AlreadyAsked;
                } else {
                    $asked[] = $puzzleId;
                }
            }

            if ($asked === []) {
                continue;
            }

            $owner = $this->playerRepository->get($ownerId);
            $request = new BorrowRequest(
                id: Uuid::uuid7(),
                asker: $asker,
                owner: $owner,
                sendId: Uuid::fromString($message->sendId),
                note: $message->notes[$ownerId] ?? null,
                createdAt: $now,
            );
            $this->borrowRequestRepository->save($request);

            foreach ($asked as $puzzleId) {
                $this->borrowRequestRepository->saveItem(new BorrowRequestItem(
                    Uuid::uuid7(),
                    $request,
                    $this->puzzleRepository->get($puzzleId),
                ));
            }

            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $owner,
                NotificationType::BorrowRequested,
                $now,
                actorPlayer: $asker,
                targetBorrowRequest: $request,
            ));

            $this->messageBus->dispatch(
                new SendBorrowRequestEmail($request->id->toString()),
                [new DispatchAfterCurrentBusStamp()],
            );

            $requestsSent++;
            $puzzlesAsked += count($asked);
        }

        return new BorrowAskOutcome($requestsSent, $puzzlesAsked, $skipped);
    }

    /**
     * Lower-case valid ids, each puzzle with the first owner it is assigned to, empty owners dropped.
     *
     * @param array<string, list<string>> $puzzlesByOwner
     * @return array<string, list<string>>
     */
    private function normalized(array $puzzlesByOwner): array
    {
        $normalized = [];
        $seen = [];

        foreach ($puzzlesByOwner as $ownerId => $puzzleIds) {
            $ownerId = strtolower((string) $ownerId);

            if (Uuid::isValid($ownerId) === false) {
                continue;
            }

            foreach ($puzzleIds as $puzzleId) {
                $puzzleId = strtolower($puzzleId);

                if (Uuid::isValid($puzzleId) === false || isset($seen[$puzzleId])) {
                    continue;
                }

                $seen[$puzzleId] = true;
                $normalized[$ownerId][] = $puzzleId;
            }
        }

        return $normalized;
    }

    /**
     * @param list<BorrowCandidate> $candidates
     * @return array<string, array<string, BorrowCandidate>>
     */
    private function candidatesByPuzzleAndOwner(array $candidates): array
    {
        $byPuzzle = [];

        foreach ($candidates as $candidate) {
            $byPuzzle[$candidate->puzzleId][$candidate->ownerId] = $candidate;
        }

        return $byPuzzle;
    }
}
