<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\BorrowRequestItem;
use SpeedPuzzling\Web\Exceptions\BorrowRequestNotFound;
use SpeedPuzzling\Web\Message\SendBorrowRequestEmail;
use SpeedPuzzling\Web\Repository\BorrowRequestRepository;
use SpeedPuzzling\Web\Services\BorrowRequest\BorrowRequestEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail to the owner about a borrow request (docs/features/wishlist-availability.md), on the notifications stream
 * (a hard-bounced address is dropped by MailStreamRouting) with a one-click unsubscribe of
 * `player.borrow_request_emails`. Read fresh: nothing is sent when the request is gone, nothing in it still waits (the
 * asker withdrew meanwhile), the owner switched these e-mails off, or has no account e-mail.
 *
 * It names who asks - asking was their consent - and which puzzles. Nobody's e-mail address is in it: the owner answers
 * in MySpeedPuzzling.
 */
#[AsMessageHandler]
readonly final class SendBorrowRequestEmailHandler
{
    public function __construct(
        private BorrowRequestRepository $borrowRequestRepository,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private BorrowRequestEmailsUnsubscribeUrl $unsubscribeUrl,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendBorrowRequestEmail $message): void
    {
        try {
            $request = $this->borrowRequestRepository->get($message->requestId);
        } catch (BorrowRequestNotFound) {
            // The asker or the owner was deleted meanwhile
            return;
        }

        $owner = $request->owner;
        $asker = $request->asker;

        $waiting = array_values(array_filter(
            $this->borrowRequestRepository->itemsOf($request),
            static fn (BorrowRequestItem $item): bool => $item->status === BorrowRequestItemStatus::Waiting,
        ));

        if ($waiting === [] || $owner->borrowRequestEmails === false) {
            return;
        }

        $ownerEmail = $this->playerAccountEmail->ofPlayer($owner);

        if ($ownerEmail === null) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($owner->locale);
        $askerName = $asker->name ?? '#' . strtoupper($asker->code);
        $ownerId = $owner->id->toString();
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($ownerId, $locale);

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($ownerEmail)
            ->locale($locale)
            ->subject($this->translator->trans('borrow_request.subject', [
                '%asker%' => $askerName,
                '%count%' => count($waiting),
            ], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/borrow_request.html.twig')
            ->context([
                'locale' => $locale,
                'ownerName' => $owner->name ?? '#' . strtoupper($owner->code),
                'askerName' => $askerName,
                'puzzles' => array_map(static fn (BorrowRequestItem $item): array => [
                    'name' => $item->puzzle->name,
                    'pieces' => $item->puzzle->piecesCount,
                    'brand' => $item->puzzle->manufacturer?->name,
                ], $waiting),
                'note' => $request->note,
                'answerUrl' => $this->urlGenerator->generate('borrow_request_detail', [
                    '_locale' => $locale,
                    'requestId' => $request->id->toString(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($ownerId, $ownerEmail, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');
        // One-click unsubscribe (RFC 8058), like the partner finder e-mails
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }
}
