<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\PartnerRequest;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Exceptions\PartnerRequestLimitReached;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotPossible;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\SendPartnerRequest;
use SpeedPuzzling\Web\Message\SendPartnerRequestEmail;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Query\PartnerFinderEligibility;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerFinderAttendance;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * "I'd like to puzzle with you" (docs/features/partner-finder.md D6). Everything is checked before anything changes.
 * Asked already = done. A block either way = nothing is saved, nobody is told, and the sender cannot tell (the
 * controller answers exactly as for a sent request). Otherwise the sender is marked going (plain events, D2), the
 * request and the receiver's notification are saved, and the e-mail goes async once this transaction committed.
 *
 * A private sender is named to the receiver: sending is their consent (the request form says so).
 */
#[AsMessageHandler]
readonly final class SendPartnerRequestHandler
{
    public function __construct(
        private PartnerListingRepository $partnerListingRepository,
        private PartnerRequestRepository $partnerRequestRepository,
        private PlayerRepository $playerRepository,
        private NotificationRepository $notificationRepository,
        private PartnerFinderEligibility $partnerFinderEligibility,
        private PartnerFinderAttendance $partnerFinderAttendance,
        private GetUserBlocks $getUserBlocks,
        private RateLimiterFactoryInterface $partnerRequestLimiter,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PartnerListingNotFound
     * @throws PlayerNotFound
     * @throws PartnerRequestNotPossible
     * @throws PartnerRequestLimitReached
     */
    public function __invoke(SendPartnerRequest $message): void
    {
        $listing = $this->partnerListingRepository->get($message->listingId);
        $sender = $this->playerRepository->get($message->fromPlayerId);
        $owner = $listing->player;
        $competition = $listing->competition;

        // The lock this message took is the listing's event's - anything else would serialize on the wrong key
        if ($competition->id->toString() !== strtolower($message->competitionId)) {
            throw new PartnerRequestNotPossible();
        }

        if ($owner->id->equals($sender->id) || $listing->isOpen() === false) {
            throw new PartnerRequestNotPossible();
        }

        if (in_array($message->kind, $listing->lookingFor(), true) === false) {
            throw new PartnerRequestNotPossible();
        }

        $offer = $this->partnerFinderEligibility->qualifyingOffer($competition->id->toString());

        if ($offer === null || $offer->offers($message->kind) === false) {
            throw new PartnerRequestNotPossible();
        }

        // Asked already (a resent form included): done
        if ($this->partnerRequestRepository->findFor($message->listingId, $message->fromPlayerId) !== null) {
            return;
        }

        // Counted before the block check, so a blocked sender reaches the limit exactly like anybody else
        if ($this->partnerRequestLimiter->create($sender->id->toString())->consume()->isAccepted() === false) {
            throw new PartnerRequestLimitReached();
        }

        if (
            $this->getUserBlocks->isBlocked($owner->id->toString(), $sender->id->toString())
            || $this->getUserBlocks->isBlocked($sender->id->toString(), $owner->id->toString())
        ) {
            return;
        }

        $now = $this->clock->now();

        $this->partnerFinderAttendance->ensureGoing($competition, $sender);

        $request = new PartnerRequest(
            id: Uuid::fromString($message->requestId),
            listing: $listing,
            fromPlayer: $sender,
            kind: $message->kind,
            note: $message->note,
            createdAt: $now,
        );
        $this->partnerRequestRepository->save($request);

        $this->notificationRepository->save(new Notification(
            Uuid::uuid7(),
            $owner,
            NotificationType::PartnerRequest,
            $now,
            actorPlayer: $sender,
            targetPartnerRequest: $request,
        ));

        $this->messageBus->dispatch(
            new SendPartnerRequestEmail($request->id->toString()),
            [new DispatchAfterCurrentBusStamp()],
        );
    }
}
