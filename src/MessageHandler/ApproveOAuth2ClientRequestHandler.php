<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\ValueObject\Grant;
use League\Bundle\OAuth2ServerBundle\ValueObject\RedirectUri;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\ApproveOAuth2ClientRequest;
use SpeedPuzzling\Web\Repository\OAuth2ClientRequestRepository;
use SpeedPuzzling\Web\Value\OAuth2ApplicationType;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
final readonly class ApproveOAuth2ClientRequestHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private OAuth2ClientRequestRepository $requestRepository,
        private ClientManagerInterface $clientManager,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ApproveOAuth2ClientRequest $message): string
    {
        $request = $this->requestRepository->get($message->requestId);
        $admin = $this->entityManager->find(Player::class, $message->adminPlayerId);
        assert($admin !== null);

        /** @var non-empty-string $identifier */
        $identifier = $this->generateClientIdentifier($request->clientName);
        $isPublic = $request->applicationType === OAuth2ApplicationType::Public;
        $secret = $isPublic ? null : bin2hex(random_bytes(32));

        $client = new Client($request->clientName, $identifier, $secret);

        $redirectUris = array_filter($request->redirectUris, static fn(string $uri) => $uri !== '');

        if ($redirectUris !== []) {
            /** @var non-empty-string[] $redirectUris */
            $client->setRedirectUris(...array_map(
                static fn(string $uri) => new RedirectUri($uri),
                $redirectUris,
            ));
        }

        $grants = [new Grant('authorization_code'), new Grant('client_credentials'), new Grant('refresh_token')];
        $client->setGrants(...$grants);

        $validScopes = array_filter($request->requestedScopes, static fn(string $scope) => $scope !== '');

        if ($validScopes !== []) {
            /** @var non-empty-string[] $validScopes */
            $client->setScopes(...array_map(
                static fn(string $scope) => new Scope($scope),
                $validScopes,
            ));
        }

        $this->clientManager->save($client);

        $claimToken = bin2hex(random_bytes(32));
        $request->approve($admin, $identifier, $secret, $claimToken);

        $this->entityManager->flush();

        $claimUrl = $this->urlGenerator->generate('claim_oauth2_credentials', [
            'claimToken' => $claimToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $playerEmail = $request->player->email;

        if ($playerEmail !== null) {
            $playerLocale = $request->player->locale ?? 'en';

            $subject = $this->translator->trans(
                'oauth2_client_approved.subject',
                domain: 'emails',
                locale: $playerLocale,
            );

            $email = (new TemplatedEmail())
                ->to($playerEmail)
                ->locale($playerLocale)
                ->subject($subject)
                ->htmlTemplate('emails/oauth2_client_approved.html.twig')
                ->context([
                    'clientName' => $request->clientName,
                    'claimUrl' => $claimUrl,
                ]);
            $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

            $this->mailer->send($email);
        }

        return $claimToken;
    }

    private function generateClientIdentifier(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name) ?? $name, '-'));

        return substr($slug, 0, 28) . '-' . bin2hex(random_bytes(2));
    }
}
