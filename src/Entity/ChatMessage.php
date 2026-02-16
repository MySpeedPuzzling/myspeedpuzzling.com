<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Events\ChatMessageSent;
use SpeedPuzzling\Web\Value\SystemMessageType;

#[Entity]
#[Index(columns: ['conversation_id', 'sent_at'])]
class ChatMessage implements EntityWithEvents
{
    use HasEvents;

    #[Immutable]
    #[Column(type: Types::STRING, nullable: true, enumType: SystemMessageType::class)]
    public null|SystemMessageType $systemMessageType;

    #[Immutable]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $systemMessageTargetPlayerId;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Conversation $conversation,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $sender,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $content,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $sentAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $readAt = null,
        null|SystemMessageType $systemMessageType = null,
        null|UuidInterface $systemMessageTargetPlayerId = null,
    ) {
        $this->systemMessageType = $systemMessageType;
        $this->systemMessageTargetPlayerId = $systemMessageTargetPlayerId;

        if ($this->sender !== null) {
            $this->recordThat(new ChatMessageSent(
                chatMessageId: $this->id,
                conversationId: $this->conversation->id,
                senderId: $this->sender->id->toString(),
            ));
        }
    }

    public function markAsRead(): void
    {
        $this->readAt = new DateTimeImmutable();
    }

    public function isSystemMessage(): bool
    {
        return $this->systemMessageType !== null;
    }
}
