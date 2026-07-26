<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\NewsletterSubscriber;
use SpeedPuzzling\Web\Exceptions\NewsletterSubscriberNotFound;

readonly class NewsletterSubscriberRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws NewsletterSubscriberNotFound
     */
    public function get(string $subscriberId): NewsletterSubscriber
    {
        if (!Uuid::isValid($subscriberId)) {
            throw new NewsletterSubscriberNotFound();
        }

        $subscriber = $this->entityManager->find(NewsletterSubscriber::class, $subscriberId);

        if ($subscriber === null) {
            throw new NewsletterSubscriberNotFound();
        }

        return $subscriber;
    }

    public function findByEmail(string $email): null|NewsletterSubscriber
    {
        return $this->entityManager->getRepository(NewsletterSubscriber::class)
            ->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    public function save(NewsletterSubscriber $subscriber): void
    {
        $this->entityManager->persist($subscriber);
    }

    public function remove(NewsletterSubscriber $subscriber): void
    {
        $this->entityManager->remove($subscriber);
    }
}
