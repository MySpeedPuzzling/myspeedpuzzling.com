<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Email;

use Doctrine\DBAL\Connection;
use Dom\HTMLDocument;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The registration e-mails follow docs/features/transactional-emails.md, and everything the organiser typed reaches
 * other people's inboxes escaped - an event name, a fee or payment instructions can never become a link in an
 * e-mail from MySpeedPuzzling.
 */
final class CompetitionRegistrationEmailTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
    }

    public function testOrganiserTextIsEscapedAndLabelledAsTheOrganisers(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition SET name = 'Cup <a href=\"https://evil.example/pay\">Pay here</a>' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        );
        $this->manage(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, entryFee: '<b>15 EUR</b>', paymentInstructions: "<a href=\"https://evil.example\">IBAN</a>\nSecond line");

        $this->messageBus->dispatch(new JoinCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, PlayerFixture::PLAYER_REGULAR));

        $email = $this->sentEmail();
        $html = (string) $email->getHtmlBody();

        self::assertStringNotContainsString('evil.example', self::hrefsOf($html));
        self::assertStringContainsString('&lt;a href=', $html);
        self::assertStringContainsString('&lt;b&gt;15 EUR&lt;/b&gt;', $html);
        self::assertStringContainsString('Second line', $html);
        self::assertStringContainsString('does not process payments', $html);
        // email_document + the preheader of main's transactional e-mails
        self::assertStringStartsWith('<!DOCTYPE html>', ltrim($html));
        self::assertStringContainsString('class="preheader"', $html);
    }

    public function testButtonOpensTheEditionsOwnPageInThePlayersLanguageAndTheTextPartHasIt(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET locale = 'cs' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        $this->manage(CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $this->messageBus->dispatch(new JoinCompetition(CompetitionSeriesFixture::EDITION_OFFLINE_1, PlayerFixture::PLAYER_REGULAR));

        $email = $this->sentEmail();
        $document = HTMLDocument::createFromString((string) $email->getHtmlBody(), LIBXML_NOERROR);
        $button = $document->querySelector('table.button a')?->getAttribute('href');
        self::assertIsString($button);
        self::assertStringStartsWith('http', $button);
        // An edition's page, never event_detail with its slug - and the Czech one
        self::assertStringNotContainsString('/en/', $button);
        self::assertStringNotContainsString('/events/', $button);
        self::assertStringContainsString($button, (string) $email->getTextBody());
    }

    private function manage(string $competitionId, null|string $entryFee = null, null|string $paymentInstructions = null): void
    {
        $this->messageBus->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: $competitionId,
            registrationManaged: true,
            capacity: 10,
            registrationOpensAt: null,
            registrationClosesAt: null,
            timezone: 'Europe/Prague',
            entryFeeText: $entryFee,
            paymentInstructions: $paymentInstructions,
        ));
    }

    private function sentEmail(): TemplatedEmail
    {
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);

        return $email;
    }

    private static function hrefsOf(string $html): string
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $hrefs = [];

        foreach ($document->getElementsByTagName('a') as $link) {
            $hrefs[] = (string) $link->getAttribute('href');
        }

        return implode(' ', $hrefs);
    }
}
