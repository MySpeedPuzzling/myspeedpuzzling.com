<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\SendParticipantClaimInvitation;
use SpeedPuzzling\Web\Query\GetParticipantClaimInvitation;
use SpeedPuzzling\Web\Repository\EmailSuppressionRepository;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Services\ParticipantLinking\ClaimInvitationsRefusalUrl;
use SpeedPuzzling\Web\Value\ClaimInvitationLocale;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The claim invitation e-mail (docs/features/competitions-management/participant-linking.md §5.5, §9.1, R8) - async, it
 * reads and sends, never writes the participant. Read again first: nothing when the person was removed, got linked,
 * said no, the address changed or was erased, or takes no e-mail from us any more (email_suppression, a complaint
 * about an invitation included), or the event is not public / uses online timing by now.
 *
 * Its own e-mail type (`email_type = participant_claim_invitation`, from the template's name - a complaint about it
 * suppresses the address for invitations, ProcessSesEventHandler), on the transactional stream. The same body for
 * everybody, an account or not (D10); no reply-to (the organisers' addresses stay private). Language: by the country the
 * organiser gave (ClaimInvitationLocale), the claim link has none. Footer link + RFC 8058 `List-Unsubscribe` = the
 * signed one-click "no more invitations for this event".
 */
#[AsMessageHandler]
readonly final class SendParticipantClaimInvitationHandler
{
    public const string TEMPLATE = 'emails/participant_claim_invitation.html.twig';

    public function __construct(
        private GetParticipantClaimInvitation $getParticipantClaimInvitation,
        private EmailSuppressionRepository $emailSuppressionRepository,
        private NameTagQrCode $nameTagQrCode,
        private ClaimInvitationsRefusalUrl $refusalUrl,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SendParticipantClaimInvitation $message): void
    {
        $invitation = $this->getParticipantClaimInvitation->byId($message->participantId);

        if ($invitation === null || $invitation->claimToken === null || $invitation->mayStillBeSentTo($message->email) === false) {
            return;
        }

        if ($this->emailSuppressionRepository->findByEmail($message->email)?->holdsBackClaimInvitations($this->clock->now()) === true) {
            return;
        }

        $locale = ClaimInvitationLocale::forCountry($invitation->participantCountry);
        $refusalUrl = $this->refusalUrl->forParticipant($invitation->participantId, $locale);

        $email = (new TemplatedEmail())
            ->to($message->email)
            ->locale($locale)
            ->subject($this->translator->trans('participant_claim_invitation.subject', [
                '%event%' => $invitation->competitionName,
            ], domain: 'emails', locale: $locale))
            ->htmlTemplate(self::TEMPLATE)
            ->context([
                'locale' => $locale,
                'participantName' => $invitation->participantName,
                'eventName' => $invitation->competitionName,
                'organizationName' => $invitation->organizationName,
                'dateFrom' => $invitation->dateFrom,
                'dateTo' => $invitation->dateTo,
                'place' => $invitation->location,
                'claimUrl' => $this->nameTagQrCode->claimUrl($invitation->claimToken),
                'refusalUrl' => $refusalUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<' . $refusalUrl . '>');
        $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $this->mailer->send($email);
    }
}
