<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\DisplayDesign;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\OnlineToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Competition displays for the read side tests (competition toolkit D3): one row inserted by SQL with a design
 * normalised by the sanitizer - uses_results / uses_live_results derived from it as the save handler does.
 */
trait CreatesCompetitionDisplays
{
    /**
     * @param list<array<string, mixed>> $elements partial elements (type + props), completed with an id and a frame
     * @param null|list<string> $followRoundIds
     * @return string the display's token
     */
    private static function createDisplay(
        string $competitionId,
        array $elements = [],
        null|string $pinnedRoundId = null,
        null|array $followRoundIds = null,
        DisplayKind $kind = DisplayKind::Screen,
    ): string {
        $container = self::getContainer();
        $design = DisplayDesign::blank($kind);

        foreach ($elements as $index => $element) {
            $design['elements'][] = [
                'id' => sprintf('el%06d', $index),
                'frame' => ['x' => 0, 'y' => 0, 'w' => 50, 'h' => 10],
                ...$element,
            ];
        }

        $normalised = $container->get(DisplayDesignSanitizer::class)->normaliseArray($design, $competitionId);
        $token = OnlineToken::generate();

        $container->get(Connection::class)->insert('competition_display', [
            'id' => Uuid::uuid7()->toString(),
            'competition_id' => $competitionId,
            'kind' => $kind->value,
            'name' => 'Test display',
            'token' => $token,
            'locale' => 'en',
            'design' => json_encode($normalised, JSON_THROW_ON_ERROR),
            'created_at' => TestClock::FROZEN_NOW,
            'updated_at' => TestClock::FROZEN_NOW,
            'design_version' => 1,
            'follow_mode' => $pinnedRoundId !== null ? 'pinned' : 'current',
            'pinned_round_id' => $pinnedRoundId,
            'follow_round_ids' => $followRoundIds !== null ? json_encode($followRoundIds, JSON_THROW_ON_ERROR) : null,
            'uses_results' => DisplayDesignSanitizer::usesResults($normalised),
            'uses_live_results' => DisplayDesignSanitizer::usesLiveResults($normalised),
        ], ['uses_results' => 'boolean', 'uses_live_results' => 'boolean']);

        return $token;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>} the raw JSON answer and its decoded form
     */
    private static function displayState(KernelBrowser $browser, string $token): array
    {
        $browser->request('GET', '/display/' . $token . '/state');
        self::assertResponseIsSuccessful();
        $raw = (string) $browser->getResponse()->getContent();
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return [$raw, $decoded];
    }

    /**
     * A value deep in a decoded answer by its dotted path ("results.top.0.name"), null when it is not there
     */
    private static function at(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }

            $data = $data[$key];
        }

        return $data;
    }
}
