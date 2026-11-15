<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SendImportAddedEmail;
use SpeedPuzzling\Web\Repository\DataImportBatchRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportEmailsUnsubscribeUrl;
use SpeedPuzzling\Web\Services\EmailPreferencesLinkGenerator;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The e-mail "your import is done" (docs/features/data-import/adding.md "Telling the player"): one per finished
 * batch, with the same numbers as the notification and a link to the import. On the notifications stream (a blocked
 * or hard-bounced address gets none - MailStreamRouting), with a signed one-click unsubscribe of
 * `player.import_emails`. Read fresh: nothing is sent when the batch is gone or not finished, the player switched
 * these e-mails off meanwhile, or has no account e-mail.
 *
 * Numbers and the file's name only - nothing of the spreadsheet's content.
 */
#[AsMessageHandler]
readonly final class SendImportAddedEmailHandler
{
    public function __construct(
        private DataImportBatchRepository $batchRepository,
        private PlayerAccountEmail $playerAccountEmail,
        private EmailPreferencesLinkGenerator $emailPreferencesLinkGenerator,
        private ImportEmailsUnsubscribeUrl $unsubscribeUrl,
        private UrlGeneratorInterface $urlGenerator,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(SendImportAddedEmail $message): void
    {
        $batch = $this->batchRepository->find($message->batchId);

        if ($batch === null || $batch->isRunning()) {
            return;
        }

        $player = $batch->import->player;

        if ($player->importEmails === false) {
            return;
        }

        $address = $this->playerAccountEmail->ofPlayer($player);

        if ($address === null) {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($player->locale);
        $playerId = $player->id->toString();
        $unsubscribeUrl = $this->unsubscribeUrl->forPlayer($playerId, $locale);

        $email = (new TemplatedEmail())
            ->from(new Address('notify@notify.myspeedpuzzling.com', 'MySpeedPuzzling'))
            ->to($address)
            ->locale($locale)
            ->subject($this->translator->trans('import_added.subject', ['%count%' => $batch->rowsAdded], domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/import_added.html.twig')
            ->context([
                'locale' => $locale,
                'playerName' => $player->name ?? '#' . strtoupper($player->code),
                'fileName' => $batch->import->fileName,
                'added' => $batch->rowsAdded,
                'results' => $batch->resultsAdded,
                'collectionItems' => $batch->collectionItemsAdded,
                'wishListItems' => $batch->wishListItemsAdded,
                'alreadyYours' => $batch->rowsAlreadyYours,
                'notAdded' => $batch->rowsNotAdded(),
                'importUrl' => $this->urlGenerator->generate('data_import_detail', [
                    '_locale' => $locale,
                    'importId' => $batch->import->id->toString(),
                ], UrlGeneratorInterface::ABSOLUTE_URL),
                'settingsUrl' => $this->emailPreferencesLinkGenerator->forPlayer($playerId, $address, $locale),
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'notifications');
        // One-click unsubscribe (RFC 8058), like the other subscription-like e-mails
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }
}
