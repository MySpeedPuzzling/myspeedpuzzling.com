<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\FeatureRequestComment;
use SpeedPuzzling\Web\Exceptions\FeatureRequestCommentNotFound;

readonly final class FeatureRequestCommentRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws FeatureRequestCommentNotFound
     */
    public function get(string $commentId): FeatureRequestComment
    {
        if (!Uuid::isValid($commentId)) {
            throw new FeatureRequestCommentNotFound();
        }

        $comment = $this->entityManager->find(FeatureRequestComment::class, $commentId);

        if ($comment === null) {
            throw new FeatureRequestCommentNotFound();
        }

        return $comment;
    }

    public function save(FeatureRequestComment $comment): void
    {
        $this->entityManager->persist($comment);
    }
}
