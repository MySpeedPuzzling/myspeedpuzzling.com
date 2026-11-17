<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\EventWizard;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\GoldenFile;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Without the event creation wizard (flag EVENT_CREATION_WIZARD off, not an admin) the event's edit page is exactly
 * what it was before the "Get ready" checklist and "Continue setting up" existed (W2,
 * docs/features/competitions-management/toolkit/W-creation-wizard-research.md §3.10) - the golden files were written
 * from the code before W2.
 */
final class EditPageWithoutWizardGoldenTest extends WebTestCase
{
    use GoldenFile;
    use OverridesFeatureFlagEnv;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function providePages(): iterable
    {
        // A published one-time event with a date ahead and no rounds
        yield 'one-time event' => [CompetitionFixture::COMPETITION_UNAPPROVED, PlayerFixture::PLAYER_REGULAR, 'edit-page-one-time-event'];
        // A draft one-time event with one round and its puzzle - the wizard could resume it
        yield 'draft' => [OrganizationFixture::COMPETITION_DRAFT_NIGHT, PlayerFixture::PLAYER_WITH_STRIPE, 'edit-page-draft'];
    }

    #[DataProvider('providePages')]
    public function testTheEditPageIsUnchangedWithoutTheWizard(string $competitionId, string $playerId, string $golden): void
    {
        $this->overrideFeatureFlagEnv('EVENT_CREATION_WIZARD', false);

        try {
            $browser = self::createClient();
            TestingLogin::asPlayer($browser, $playerId);
            $browser->request('GET', '/en/edit-event/' . $competitionId);
            self::assertResponseIsSuccessful();

            self::assertMatchesGoldenFile(
                __DIR__ . '/golden/' . $golden . '.html',
                self::normalized((string) $browser->getResponse()->getContent()),
                'The edit page without the wizard (' . $golden . ')',
            );
        } finally {
            $this->restoreFeatureFlagEnv();
        }
    }

    /**
     * Per-request values (tokens, nonces, the render time) and asset hashes out - everything else byte for byte
     */
    private static function normalized(string $html): string
    {
        $html = (string) preg_replace('~(/build/[^"\'\s]+?)\.[0-9a-f]{8,20}\.(js|css|svg|png|webp|woff2?)~', '$1.$2', $html);
        $html = (string) preg_replace('~(integrity=")[^"]*(")~', '$1$2', $html);
        $html = (string) preg_replace('~(nonce=")[^"]*(")~', '$1$2', $html);
        $html = (string) preg_replace('~(<meta name="msp-rendered-at" content=")\d+(")~', '$1$2', $html);

        return (string) preg_replace('~((?:_token|csrf[_-]?token|data-csrf[a-z-]*)["\']?\s*(?:value|content|=|:)\s*=?\s*["\'])[^"\']+~i', '$1', $html);
    }
}
