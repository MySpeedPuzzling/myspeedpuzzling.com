<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotFound;
use SpeedPuzzling\Web\Message\ReplyToPartnerRequest;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reply = a chat from the listed puzzler's side (docs/features/partner-finder.md D6): StartConversation with the
 * request, which lets it through the asker's "direct messages off" after checking the request itself. Only the
 * listing's owner may reply - anybody else gets the same 404 as for a request that does not exist. Replying again
 * after the chat began only leads back to it.
 */
#[AsMessageHandler]
readonly final class ReplyToPartnerRequestHandler
{
    public function __construct(
        private PartnerRequestRepository $partnerRequestRepository,
        private ConversationRepository $conversationRepository,
        private MessageBusInterface $messageBus,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PartnerRequestNotFound
     */
    public function __invoke(ReplyToPartnerRequest $message): void
    {
        $request = $this->partnerRequestRepository->get($message->requestId);
        $replier = $request->listing->player;
        $asker = $request->fromPlayer;

        if ($replier->id->toString() !== strtolower($message->playerId)) {
            throw new PartnerRequestNotFound();
        }

        if ($request->isReplied() && $this->conversationRepository->findAcceptedBetween($replier, $asker) !== null) {
            return;
        }

        $request->markReplied($this->clock->now());

        $locale = ListmonkNewsletterLists::normalizeLocale($message->locale ?? $replier->locale);

        $this->messageBus->dispatch(new StartConversation(
            initiatorId: $replier->id->toString(),
            recipientId: $asker->id->toString(),
            initialMessage: $this->translator->trans('partner_finder.request.reply_message', [
                '%name%' => $asker->name ?? $asker->code,
                '%event%' => $request->listing->competition->name,
            ], locale: $locale),
            partnerRequestId: $request->id->toString(),
        ));
    }
}
