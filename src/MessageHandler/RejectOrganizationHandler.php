<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RejectOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * As a series: the organization is rejected with a reason, its creator is e-mailed. Its series and events keep their
 * own state (an organization never hides them).
 */
#[AsMessageHandler]
readonly final class RejectOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
    ) {
    }

    public function __invoke(RejectOrganization $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);
        $rejectedBy = $this->playerRepository->get($message->rejectedByPlayerId);

        $organization->reject($rejectedBy, $this->clock->now(), $message->reason);

        $creator = $organization->addedByPlayer;
        $creatorEmail = $creator === null ? null : $this->playerAccountEmail->ofPlayer($creator);

        if ($creator === null || $creatorEmail === null) {
            return;
        }

        $playerLocale = $creator->locale ?? 'en';

        $email = (new TemplatedEmail())
            ->to($creatorEmail)
            ->locale($playerLocale)
            ->subject($this->translator->trans('competition_rejected.subject', domain: 'emails', locale: $playerLocale))
            ->htmlTemplate('emails/competition_rejected.html.twig')
            ->context([
                'competitionName' => $organization->name,
                'reason' => $message->reason,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
