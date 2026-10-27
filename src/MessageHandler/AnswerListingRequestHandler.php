<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ListingRequest;
use SpeedPuzzling\Web\Entity\ListingRequestItem;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\ListingRequestNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\AnswerListingRequest;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromSellSwapList;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\ListingRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use SpeedPuzzling\Web\Services\ListingReservation;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\ListingRequestAnswerOutcome;
use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The seller answers a listing request (docs/features/marketplace/12-listing-requests.md). Only the seller may - anybody
 * else gets the same 404 as for a request that does not exist. Only waiting listings change, so a resent form changes
 * nothing.
 *
 * - Yes reserves the listing for the buyer through ListingReservation (the "reserved" system message in every
 *   conversation about it, like a reservation by hand). A listing reserved for somebody else meanwhile is refused and
 *   stays waiting - the seller removes that reservation first. Already reserved for this buyer = yes without a new
 *   reservation (and the request never takes it back).
 * - "I don't have it any more" may take the listing off the seller's sell/swap list (the existing
 *   RemovePuzzlesFromSellSwapList - every other open ask of it becomes unavailable).
 * - "Not this time" only answers.
 *
 * The buyer is told when something changed. A yes opens a chat from the seller's side (accepted from the start, the
 * buyer asked first - StartConversation::$listingRequestId), and so does "Reply in chat". A block either way since the
 * request was sent: the answers are kept, nobody is told and no chat opens - silently. Muted messaging refuses only
 * "Reply in chat".
 */
#[AsMessageHandler]
readonly final class AnswerListingRequestHandler
{
    public function __construct(
        private ListingRequestRepository $listingRequestRepository,
        private NotificationRepository $notificationRepository,
        private ConversationRepository $conversationRepository,
        private UserBlockRepository $userBlockRepository,
        private ListingReservation $listingReservation,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ListingRequestNotFound
     * @throws MessagingMuted
     */
    public function __invoke(AnswerListingRequest $message): ListingRequestAnswerOutcome
    {
        $request = $this->listingRequestRepository->get($message->requestId);
        $seller = $request->seller;
        $buyer = $request->buyer;

        if ($seller->id->toString() !== strtolower($message->playerId)) {
            throw new ListingRequestNotFound();
        }

        $now = $this->clock->now();
        $blocked = $this->userBlockRepository->findByBlockerAndBlocked($buyer, $seller) !== null
            || $this->userBlockRepository->findByBlockerAndBlocked($seller, $buyer) !== null;
        $muted = $seller->isMessagingMuted($now);

        // Refused before anything changes - the replier's own state, never the other side's
        if ($message->openChat && $muted && $blocked === false) {
            throw new MessagingMuted();
        }

        $items = $this->listingRequestRepository->itemsOf($request);
        $changed = 0;
        $refused = 0;
        $saidYes = false;

        foreach ($items as $item) {
            $answer = ListingRequestItemStatus::tryFrom($message->answers[$item->id->toString()] ?? '');

            if ($answer === null || $answer->isAnswer() === false || $item->status !== ListingRequestItemStatus::Waiting) {
                continue;
            }

            if ($answer === ListingRequestItemStatus::Yes) {
                $listing = $item->listing;

                // Gone already (closed by ListingRequestCloser) or reserved for somebody else - stays waiting
                if ($listing === null || ($listing->reserved && $listing->reservedForPlayerId?->equals($buyer->id) !== true)) {
                    $refused++;
                    continue;
                }

                $reserve = $listing->reserved === false;
                $item->answer($answer, $now, reservedByRequest: $reserve);

                if ($reserve) {
                    $this->listingReservation->reserve($listing, $buyer->id);
                }

                $changed++;
                $saidYes = true;
                continue;
            }

            if ($item->answer($answer, $now)) {
                $changed++;
            }
        }

        $leavingList = array_values(array_map(
            static fn (ListingRequestItem $item): string => $item->puzzle->id->toString(),
            array_filter(
                $items,
                static fn (ListingRequestItem $item): bool => $item->status === ListingRequestItemStatus::Gone
                    && $item->listing !== null
                    && in_array($item->id->toString(), $message->removeFromList, true),
            ),
        ));

        if ($leavingList !== []) {
            $this->messageBus->dispatch(new RemovePuzzlesFromSellSwapList($seller->id->toString(), $leavingList));
        }

        if ($blocked) {
            return new ListingRequestAnswerOutcome($changed, $refused);
        }

        if ($changed > 0) {
            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $buyer,
                NotificationType::ListingRequestAnswered,
                $now,
                actorPlayer: $seller,
                targetListingRequest: $request,
            ));
        }

        if (($saidYes && $muted === false) || $message->openChat) {
            $this->openChat($request, $items, $message->locale);
        }

        return new ListingRequestAnswerOutcome($changed, $refused);
    }

    /**
     * @param list<ListingRequestItem> $items
     */
    private function openChat(ListingRequest $request, array $items, null|string $locale): void
    {
        $seller = $request->seller;
        $buyer = $request->buyer;

        if ($request->isReplied() && $this->conversationRepository->findAcceptedBetween($seller, $buyer) !== null) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($locale ?? $seller->locale);
        $yes = array_values(array_map(
            static fn (ListingRequestItem $item): string => $item->puzzle->name,
            array_filter($items, static fn (ListingRequestItem $item): bool => $item->status === ListingRequestItemStatus::Yes),
        ));
        $buyerName = $buyer->name ?? '#' . strtoupper($buyer->code);
        $url = $this->urlGenerator->generate('listing_request_detail', [
            '_locale' => $locale,
            'requestId' => $request->id->toString(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->messageBus->dispatch(new StartConversation(
            initiatorId: $seller->id->toString(),
            recipientId: $buyer->id->toString(),
            initialMessage: $yes !== []
                ? $this->translator->trans('listing_request.chat.yes', [
                    '%name%' => $buyerName,
                    '%puzzles%' => implode(', ', $yes),
                    '%url%' => $url,
                    '%count%' => count($yes),
                ], locale: $locale)
                : $this->translator->trans('listing_request.chat.reply', ['%name%' => $buyerName, '%url%' => $url], locale: $locale),
            listingRequestId: $request->id->toString(),
        ));

        $request->markReplied($this->clock->now());
    }
}
