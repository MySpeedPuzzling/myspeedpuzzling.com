<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionSlug;
use SpeedPuzzling\Web\Message\AddCompetition;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\CompetitionSlugGenerator;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class AddCompetitionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private CompetitionSlugGenerator $slugGenerator,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws CompetitionSlugTaken
     * @throws InvalidCompetitionSlug
     */
    public function __invoke(AddCompetition $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        if ($message->slug !== null) {
            if (CompetitionSlugGenerator::isValid($message->slug) === false) {
                throw new InvalidCompetitionSlug($message->slug);
            }

            if ($this->slugGenerator->isTaken($message->slug, null)) {
                throw new CompetitionSlugTaken($message->slug);
            }
        }

        $slug = $message->slug ?? $this->slugGenerator->generate($message->name);

        $logoPath = null;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $now->getTimestamp();
            $logoPath = "competitions/{$message->competitionId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $competition = new Competition(
            id: $message->competitionId,
            name: $message->name,
            slug: $slug,
            shortcut: $message->shortcut,
            logo: $logoPath,
            description: $message->description,
            link: $message->link,
            registrationLink: $message->registrationLink,
            resultsLink: $message->resultsLink,
            location: $message->location,
            locationCountryCode: $message->locationCountryCode,
            dateFrom: $message->dateFrom,
            dateTo: $message->dateTo,
            tag: null,
            isOnline: $message->isOnline,
            addedByPlayer: $player,
            createdAt: $now,
        );

        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $competition->maintainers->add($maintainer);
        }

        $this->entityManager->persist($competition);
        $this->entityManager->flush();

        if ($message->notifyAdmin === false) {
            return;
        }

        $adminUrl = $this->urlGenerator->generate('admin_competition_approvals', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $subject = $this->translator->trans(
            'competition_submitted.subject',
            ['%competitionName%' => $message->name],
            domain: 'emails',
        );

        $email = (new TemplatedEmail())
            ->to('jan.mikes@myspeedpuzzling.com')
            ->subject($subject)
            ->htmlTemplate('emails/competition_submitted.html.twig')
            ->context([
                'playerName' => $player->name ?? 'Unknown',
                'competitionName' => $message->name,
                'location' => $message->location,
                'adminUrl' => $adminUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }
}
