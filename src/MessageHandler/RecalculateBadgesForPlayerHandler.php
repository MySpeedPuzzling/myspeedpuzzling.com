<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Entity\Badge;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Badges\BadgeEvaluator;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class RecalculateBadgesForPlayerHandler
{
    public function __construct(
        private BadgeEvaluator $badgeEvaluator,
        private PlayerRepository $playerRepository,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RecalculateBadgesForPlayer $message): void
    {
        $newBadges = $this->badgeEvaluator->recalculateForPlayer($message->playerId);

        if ($newBadges === []) {
            return;
        }

        try {
            $player = $this->playerRepository->get($message->playerId);
        } catch (PlayerNotFound) {
            return;
        }

        if ($player->email === null) {
            return;
        }

        $highestPerType = $this->keepHighestTierPerType($newBadges);

        $subject = $this->translator->trans(
            'badges_earned.subject',
            domain: 'emails',
            locale: $player->locale,
        );

        $email = (new TemplatedEmail())
            ->to($player->email)
            ->locale($player->locale)
            ->subject($subject)
            ->htmlTemplate('emails/badges_earned.html.twig')
            ->context([
                'badges' => $highestPerType,
                'locale' => $player->locale,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }

    /**
     * @param list<Badge> $badges
     * @return list<array{type: BadgeType, tier: null|BadgeTier}>
     */
    private function keepHighestTierPerType(array $badges): array
    {
        $bestByType = [];

        foreach ($badges as $badge) {
            $tier = $badge->tier === null ? null : BadgeTier::from($badge->tier);
            $typeKey = $badge->type->value;
            $existing = $bestByType[$typeKey] ?? null;

            if ($existing === null) {
                $bestByType[$typeKey] = [
                    'type' => $badge->type,
                    'tier' => $tier,
                ];
                continue;
            }

            $existingTierValue = $existing['tier'] === null ? 0 : $existing['tier']->value;
            $newTierValue = $tier === null ? 0 : $tier->value;

            if ($newTierValue > $existingTierValue) {
                $bestByType[$typeKey] = [
                    'type' => $badge->type,
                    'tier' => $tier,
                ];
            }
        }

        return array_values($bestByType);
    }
}
