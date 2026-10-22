<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\PartnerListingNotFound;
use SpeedPuzzling\Web\Message\ClosePartnerListing;
use SpeedPuzzling\Web\Message\SendPartnerTeamedUpEmail;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PartnerListingRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PartnerFinder\PartnerTeamUpCandidates;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\PartnerListingClosedReason;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * "Found my partner" / "Remove listing" (docs/features/partner-finder.md D7) - the owner's own two reasons; the others
 * (not going, event over, entered) close a listing by themselves. "Found" counts a group still needing more than one
 * person down instead of closing (PartnerListing::reduceNeeded()). A closed listing stays closed with its first reason.
 *
 * "Found" may name who they teamed up with: each named player who is a PartnerTeamUpCandidates candidate (exchanged a
 * request with the owner at the event, still listed there, no block either way) gets a PartnerTeamedUp notification
 * asking them to close their listing too - never closed for them (docs/features/partner-finder.md "Phase 2 as built").
 */
#[AsMessageHandler]
readonly final class ClosePartnerListingHandler
{
    public function __construct(
        private PartnerListingRepository $listingRepository,
        private PartnerTeamUpCandidates $partnerTeamUpCandidates,
        private PlayerRepository $playerRepository,
        private NotificationRepository $notificationRepository,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PartnerListingNotFound
     */
    public function __invoke(ClosePartnerListing $message): void
    {
        $listing = $this->listingRepository->get($message->listingId);

        if ($listing->player->id->toString() !== strtolower($message->playerId)) {
            throw new PartnerListingNotFound();
        }

        $now = $this->clock->now();
        $wasOpen = $listing->isOpen();
        $teamedUpWith = [];

        if ($message->reason === PartnerListingClosedReason::Found && $wasOpen && $message->teamedUpWith !== []) {
            $named = array_map(strtolower(...), $message->teamedUpWith);
            $candidates = array_filter(
                $this->partnerTeamUpCandidates->of($listing),
                static fn (string $candidateId): bool => in_array($candidateId, $named, true),
            );

            // Loaded before anything changes - a handler validates first; told once per listing (a group counting down
            // names the same people again, a resent form too)
            foreach ($candidates as $candidateId) {
                $candidate = $this->playerRepository->get($candidateId);

                if ($this->notificationRepository->hasPartnerListingNotification($candidate, $listing, NotificationType::PartnerTeamedUp) === false) {
                    $teamedUpWith[] = $candidate;
                }
            }
        }

        match ($message->reason) {
            PartnerListingClosedReason::Found => $listing->reduceNeeded($now),
            PartnerListingClosedReason::Withdrawn => $listing->close(PartnerListingClosedReason::Withdrawn, $now),
            default => throw new \InvalidArgumentException(sprintf('A listing\'s owner closes it as found or withdrawn, not "%s".', $message->reason->value)),
        };

        foreach ($teamedUpWith as $partner) {
            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $partner,
                NotificationType::PartnerTeamedUp,
                $now,
                actorPlayer: $listing->player,
                targetPartnerListing: $listing,
            ));

            // And an e-mail with the way to update their listing (Jan: never closed for them - they agree by closing it)
            $this->messageBus->dispatch(
                new SendPartnerTeamedUpEmail($listing->id->toString(), $partner->id->toString()),
                [new DispatchAfterCurrentBusStamp()],
            );
        }
    }
}
