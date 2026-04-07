<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionSeries;
use SpeedPuzzling\Web\Message\AddCompetitionSeries;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsMessageHandler]
readonly final class AddCompetitionSeriesHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private ImageOptimizer $imageOptimizer,
        private SluggerInterface $slugger,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(AddCompetitionSeries $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $now = $this->clock->now();

        $slug = $this->generateUniqueSlug($message->name);

        $logoPath = null;
        if ($message->logo !== null) {
            $extension = $message->logo->guessExtension();
            $timestamp = $now->getTimestamp();
            $logoPath = "competitions/{$message->seriesId}-{$timestamp}.{$extension}";

            $this->imageOptimizer->optimize($message->logo->getPathname());

            $stream = fopen($message->logo->getPathname(), 'rb');
            $this->filesystem->writeStream($logoPath, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $series = new CompetitionSeries(
            id: $message->seriesId,
            name: $message->name,
            slug: $slug,
            logo: $logoPath,
            description: $message->description,
            link: $message->link,
            isOnline: $message->isOnline,
            location: $message->location,
            locationCountryCode: $message->locationCountryCode,
            shortcut: $message->shortcut,
            addedByPlayer: $player,
            createdAt: $now,
        );

        foreach ($message->maintainerIds as $maintainerId) {
            $maintainer = $this->playerRepository->get($maintainerId);
            $series->maintainers->add($maintainer);
        }

        $this->entityManager->persist($series);
        $this->entityManager->flush();

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
                'location' => $message->location ?? 'Online',
                'adminUrl' => $adminUrl,
            ]);
        $email->getHeaders()->addTextHeader('X-Transport', 'transactional');

        $this->mailer->send($email);
    }

    private function generateUniqueSlug(string $name): string
    {
        $slug = (string) $this->slugger->slug(strtolower($name));

        /** @var int|string $existingCount */
        $existingCount = $this->entityManager->getConnection()
            ->executeQuery(
                'SELECT COUNT(*) FROM competition_series WHERE slug = :slug',
                ['slug' => $slug],
            )
            ->fetchOne();
        $existingCount = (int) $existingCount;

        if ($existingCount > 0) {
            $slug .= '-' . substr(md5(uniqid()), 0, 6);
        }

        return $slug;
    }
}
