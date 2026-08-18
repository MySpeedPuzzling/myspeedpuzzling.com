<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\NewsletterSubscriber;
use SpeedPuzzling\Web\Query\GetNewsletterRecipients;
use SpeedPuzzling\Web\Repository\NewsletterSubscriberRepository;
use SpeedPuzzling\Web\Services\Listmonk\ListmonkNewsletterLists;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Message\SubscribeToNewsletter;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public newsletter signup (footer form), double opt-in: records the request
 * and sends a confirmation e-mail. Nothing is subscribed and nothing reaches
 * Listmonk until the recipient clicks the confirmation link. Works for both
 * unknown visitors (guest subscriber row) and e-mails belonging to an existing
 * player (the confirm link then re-enables the player's newsletter toggle).
 */
#[AsMessageHandler]
readonly final class SubscribeToNewsletterHandler
{
    public function __construct(
        private GetNewsletterRecipients $getNewsletterRecipients,
        private NewsletterSubscriberRepository $newsletterSubscriberRepository,
        private NewsletterTokenSigner $tokenSigner,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urlGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SubscribeToNewsletter $message): void
    {
        $email = mb_strtolower(trim($message->email));

        if ($email === '') {
            return;
        }

        $locale = ListmonkNewsletterLists::normalizeLocale($message->locale);

        $recipient = $this->getNewsletterRecipients->byEmail($email);

        if ($recipient !== null && $recipient->audience === NewsletterAudience::Player) {
            $audience = NewsletterAudience::Player;
            $targetId = $recipient->id;
        } else {
            $subscriber = $this->newsletterSubscriberRepository->findByEmail($email);

            if ($subscriber === null) {
                $subscriber = new NewsletterSubscriber(
                    id: Uuid::uuid7(),
                    email: $email,
                    locale: $locale,
                    createdAt: $this->clock->now(),
                    ipAddress: $message->ipAddress,
                );

                $this->newsletterSubscriberRepository->save($subscriber);
            } else {
                $subscriber->startNewOptIn($locale, $message->ipAddress);
            }

            $audience = NewsletterAudience::Guest;
            $targetId = $subscriber->id->toString();
        }

        $token = $this->tokenSigner->generateConfirmToken($audience, $targetId, $email);

        $confirmUrl = $this->urlGenerator->generate(
            'newsletter_confirm',
            ['_locale' => $locale, 'token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $confirmationEmail = (new TemplatedEmail())
            ->to($email)
            ->locale($locale)
            ->subject($this->translator->trans('newsletter_confirmation.subject', domain: 'emails', locale: $locale))
            ->htmlTemplate('emails/newsletter_confirmation.html.twig')
            ->context([
                'confirmUrl' => $confirmUrl,
                'expiresHours' => NewsletterTokenSigner::CONFIRM_LIFETIME_HOURS,
            ]);

        $confirmationEmail->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($confirmationEmail);
    }
}
