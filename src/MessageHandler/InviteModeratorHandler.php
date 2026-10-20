<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ModeratorInvitation;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\InviteModerator;
use SpeedPuzzling\Web\Repository\ModeratorInvitationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\PlayerAccountEmail;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Inviting changes nothing about the player's rights: the role comes only with AcceptModeratorInvitation, after
 * the guide. A moderator is not invited again, and a player with an open invitation is not asked twice.
 */
#[AsMessageHandler]
readonly final class InviteModeratorHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ModeratorInvitationRepository $moderatorInvitationRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private PlayerAccountEmail $playerAccountEmail,
    ) {
    }

    public function __invoke(InviteModerator $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->isModerator() || $this->moderatorInvitationRepository->findPendingForPlayer($message->playerId) !== null) {
            return;
        }

        $invitedBy = $message->invitedByPlayerId !== null ? $this->playerRepository->get($message->invitedByPlayerId) : null;
        $now = $this->clock->now();

        $this->moderatorInvitationRepository->save(new ModeratorInvitation(
            $message->invitationId,
            $player,
            $invitedBy,
            $now,
        ));

        $this->entityManager->persist(new Notification(
            Uuid::uuid7(),
            $player,
            NotificationType::ModeratorInvitationReceived,
            $now,
        ));

        $this->sendInvitationEmail($player, $invitedBy);
    }

    private function sendInvitationEmail(Player $player, null|Player $invitedBy): void
    {
        $playerEmail = $this->playerAccountEmail->ofPlayer($player);

        if ($playerEmail === null) {
            return;
        }

        $locale = $player->locale ?? 'en';

        $email = (new TemplatedEmail())
            ->to($playerEmail)
            ->locale($locale)
            ->subject($this->translator->trans('moderator_invitation.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/moderator_invitation.html.twig')
            ->context([
                'playerName' => $player->name ?? $player->code,
                'invitedByName' => $invitedBy?->name,
                'guideUrl' => $this->urlGenerator->generate('moderator_guide', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
