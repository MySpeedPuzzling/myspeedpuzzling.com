<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Organizations;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "submitted for approval" e-mail to the admin - an event, a series or an organization entered the approval queue:
 * created (not as a draft, not approved at once) or published while still waiting for approval
 * (docs/features/organizations/README.md "Approval").
 */
readonly final class CompetitionSubmittedMailer
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function notifyAdmin(string $name, string $submittedBy, null|string $location): void
    {
        $adminUrl = $this->urlGenerator->generate('admin_competition_approvals', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $subject = $this->translator->trans(
            'competition_submitted.subject',
            ['%competitionName%' => $name],
            domain: 'emails',
        );

        $email = (new TemplatedEmail())
            ->to('jan.mikes@myspeedpuzzling.com')
            ->subject($subject)
            ->htmlTemplate('emails/competition_submitted.html.twig')
            ->context([
                'playerName' => $submittedBy,
                'competitionName' => $name,
                'location' => $location,
                'adminUrl' => $adminUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }

    /**
     * The organization variant: "New organization submitted" with its region.
     */
    public function notifyAdminOfOrganization(string $name, string $submittedBy, null|string $region): void
    {
        $email = (new TemplatedEmail())
            ->to('jan.mikes@myspeedpuzzling.com')
            ->subject($this->translator->trans('organization_submitted.subject', ['%organizationName%' => $name], domain: 'emails'))
            ->htmlTemplate('emails/organization_submitted.html.twig')
            ->context([
                'playerName' => $submittedBy,
                'organizationName' => $name,
                'region' => $region,
                'adminUrl' => $this->urlGenerator->generate('admin_competition_approvals', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
