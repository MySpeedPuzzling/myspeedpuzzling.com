<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\OrganizationNotApprovable;
use SpeedPuzzling\Web\Message\ApproveOrganization;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * docs/features/organizations/README.md "Approval": the organization, then its series and one-time events waiting for
 * approval (P2, OrganizationApprovalPolicy); its creator gets the "approved" e-mail.
 */
#[AsMessageHandler]
readonly final class ApproveOrganizationHandler
{
    public function __construct(
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
        private OrganizationApprovalPolicy $organizationApprovalPolicy,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
    ) {
    }

    /**
     * @throws OrganizationNotApprovable
     */
    public function __invoke(ApproveOrganization $message): void
    {
        $organization = $this->organizationRepository->get($message->organizationId);

        if ($organization->isApproved() || $organization->isRejected()) {
            throw new OrganizationNotApprovable();
        }

        $approvedBy = $this->playerRepository->get($message->approvedByPlayerId);
        $now = $this->clock->now();

        $organization->approve($approvedBy, $now);
        $this->organizationApprovalPolicy->approvePendingItemsOf($organization, $approvedBy, $now);

        if ($message->notifyCreator === false) {
            return;
        }

        $creator = $organization->addedByPlayer;
        $creatorEmail = $creator === null ? null : $this->playerAccountEmail->ofPlayer($creator);

        if ($creator === null || $creatorEmail === null) {
            return;
        }

        $playerLocale = $creator->locale ?? 'en';

        $email = (new TemplatedEmail())
            ->to($creatorEmail)
            ->locale($playerLocale)
            ->subject($this->translator->trans('competition_approved.subject', domain: 'emails', locale: $playerLocale))
            ->htmlTemplate('emails/competition_approved.html.twig')
            ->context([
                'competitionName' => $organization->name,
                'eventUrl' => $this->urlGenerator->generate('organization_detail', [
                    'slug' => $organization->slug,
                    '_locale' => $playerLocale,
                ], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
