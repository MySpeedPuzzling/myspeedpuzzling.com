<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use SpeedPuzzling\Web\Query\GetConversationLog;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ViewConversationLogController extends AbstractController
{
    public function __construct(
        private readonly GetConversationLog $getConversationLog,
    ) {
    }

    #[Route(
        path: '/admin/moderation/conversation/{conversationId}',
        name: 'admin_conversation_log',
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(string $conversationId): Response
    {
        $messages = $this->getConversationLog->fullLog($conversationId);

        return $this->render('admin/moderation/conversation_log.html.twig', [
            'messages' => $messages,
            'conversation_id' => $conversationId,
        ]);
    }
}
