<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Emails;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The claim invitation's inbox preview line (docs/features/transactional-emails.md "Preheader": under 90 characters) -
 * in every locale that has it.
 */
final class ParticipantClaimInvitationTextsTest extends TestCase
{
    public function testThePreheaderIsShorterThanNinetyCharacters(): void
    {
        $checked = 0;

        foreach (glob(__DIR__ . '/../../translations/emails.*.yml') ?: [] as $file) {
            $texts = Yaml::parseFile($file);
            self::assertIsArray($texts);
            $invitation = $texts['participant_claim_invitation'] ?? null;
            $preheader = is_array($invitation) ? ($invitation['preheader'] ?? null) : null;

            if (!is_string($preheader)) {
                continue;
            }

            self::assertLessThan(90, mb_strlen($preheader), basename($file));
            $checked++;
        }

        self::assertGreaterThanOrEqual(1, $checked, 'The English preheader at least');
    }
}
