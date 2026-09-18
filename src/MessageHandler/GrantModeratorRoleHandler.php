<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class GrantModeratorRoleHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(GrantModeratorRole $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        // Already a moderator: nothing changes, and nobody is welcomed twice
        if ($player->isModerator()) {
            return;
        }

        $now = $this->clock->now();
        $player->grantModeratorRole($now);

        $this->entityManager->persist(new Notification(
            Uuid::uuid7(),
            $player,
            NotificationType::ModeratorRoleGranted,
            $now,
        ));

        $this->sendWelcomeEmail($player);
    }

    private function sendWelcomeEmail(Player $player): void
    {
        if ($player->email === null) {
            return;
        }

        $locale = $player->locale ?? 'en';

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($locale)
            ->subject($this->translator->trans('moderator_role_granted.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/moderator_role_granted.html.twig')
            ->context([
                'playerName' => $player->name ?? $player->code,
                'changeRequestsUrl' => $this->urlGenerator->generate('admin_puzzle_change_requests', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'mergeRequestsUrl' => $this->urlGenerator->generate('admin_puzzle_merge_requests', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
