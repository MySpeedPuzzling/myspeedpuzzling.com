<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\BorrowRequest;
use SpeedPuzzling\Web\Entity\BorrowRequestItem;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\AnswerBorrowRequest;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromAllCollections;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\UserBlockRepository;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The owner answers a borrow request (docs/features/wishlist-availability.md). Only the owner may - anybody else gets
 * the same 404 as for a request that does not exist. Only puzzles still waiting change, so a resent form changes
 * nothing. "I don't have it any more" may take the puzzle out of every collection of the owner (the existing
 * RemovePuzzlesFromAllCollections) - asking fixes outdated collections.
 *
 * The asker is told when something changed. A "yes" opens a chat from the owner's side (accepted from the start, the
 * asker asked first - StartConversation::$borrowRequestId), and so does "Reply in chat". A block either way since the
 * request was sent: the answers are kept, nobody is told and no chat opens - silently, so the owner can never tell the
 * asker blocked them. Muted messaging refuses only "Reply in chat".
 */
#[AsMessageHandler]
readonly final class AnswerBorrowRequestHandler
{
    public function __construct(
        private BorrowRequestRepository $borrowRequestRepository,
        private NotificationRepository $notificationRepository,
        private ConversationRepository $conversationRepository,
        private UserBlockRepository $userBlockRepository,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws BorrowRequestNotFound
     * @throws MessagingMuted
     */
    public function __invoke(AnswerBorrowRequest $message): void
    {
        $request = $this->borrowRequestRepository->get($message->requestId);
        $owner = $request->owner;
        $asker = $request->asker;

        if ($owner->id->toString() !== strtolower($message->playerId)) {
            throw new BorrowRequestNotFound();
        }

        $now = $this->clock->now();
        $blocked = $this->userBlockRepository->findByBlockerAndBlocked($asker, $owner) !== null
            || $this->userBlockRepository->findByBlockerAndBlocked($owner, $asker) !== null;
        $muted = $owner->isMessagingMuted($now);

        // Refused before anything changes - the replier's own state, never the other side's
        if ($message->openChat && $muted && $blocked === false) {
            throw new MessagingMuted();
        }

        $items = $this->borrowRequestRepository->itemsOf($request);
        $changed = false;
        $saidYes = false;

        foreach ($items as $item) {
            $answer = BorrowRequestItemStatus::tryFrom($message->answers[$item->id->toString()] ?? '');

            if ($answer === null || $answer->isAnswer() === false) {
                continue;
            }

            if ($item->answer($answer, $now)) {
                $changed = true;
                $saidYes = $saidYes || $answer === BorrowRequestItemStatus::Yes;
            }
        }

        $leavingCollection = array_values(array_map(
            static fn (BorrowRequestItem $item): string => $item->puzzle->id->toString(),
            array_filter(
                $items,
                static fn (BorrowRequestItem $item): bool => $item->status === BorrowRequestItemStatus::NotOwned
                    && in_array($item->id->toString(), $message->removeFromCollection, true),
            ),
        ));

        if ($leavingCollection !== []) {
            $this->messageBus->dispatch(new RemovePuzzlesFromAllCollections($owner->id->toString(), $leavingCollection));
        }

        if ($blocked) {
            return;
        }

        if ($changed) {
            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $asker,
                NotificationType::BorrowRequestAnswered,
                $now,
                actorPlayer: $owner,
                targetBorrowRequest: $request,
            ));
        }

        if (($saidYes && $muted === false) || $message->openChat) {
            $this->openChat($request, $items, $message->locale);
        }
    }

    /**
     * @param list<BorrowRequestItem> $items
     */
    private function openChat(BorrowRequest $request, array $items, null|string $locale): void
    {
        $owner = $request->owner;
        $asker = $request->asker;

        if ($request->isReplied() && $this->conversationRepository->findAcceptedBetween($owner, $asker) !== null) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($locale ?? $owner->locale);
        $yes = array_values(array_map(
            static fn (BorrowRequestItem $item): string => $item->puzzle->name,
            array_filter($items, static fn (BorrowRequestItem $item): bool => $item->status === BorrowRequestItemStatus::Yes),
        ));
        $askerName = $asker->name ?? '#' . strtoupper($asker->code);

        $this->messageBus->dispatch(new StartConversation(
            initiatorId: $owner->id->toString(),
            recipientId: $asker->id->toString(),
            initialMessage: $yes !== []
                ? $this->translator->trans('borrow_request.chat.yes', [
                    '%name%' => $askerName,
                    '%puzzles%' => implode(', ', $yes),
                ], locale: $locale)
                : $this->translator->trans('borrow_request.chat.reply', ['%name%' => $askerName], locale: $locale),
            borrowRequestId: $request->id->toString(),
        ));

        $request->markReplied($this->clock->now());
    }
}
