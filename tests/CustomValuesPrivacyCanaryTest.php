<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\CustomFieldType;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A pair's result is one row of two players, and each has their own private values on it
 * (docs/features/custom-fields.md "Private by construction"). The canary: A and B solved a puzzle together, A wrote
 * into A's own fields - and nothing B, another player or a guest can open carries A's values, the names of A's
 * fields or their ids. **Add every new surface that shows a result or a player here.**
 */
final class CustomValuesPrivacyCanaryTest extends WebTestCase
{
    private const string A = PlayerFixture::PLAYER_REGULAR;
    private const string A_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string B = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string C = PlayerFixture::PLAYER_WITH_STRIPE;

    // Words that appear nowhere else in the app
    private const string FIELD_NAME = 'Quokka ledger';
    private const string CHOICE_FIELD_NAME = 'Wombat mood';
    private const string TEXT_VALUE = 'axolotl-whispers-at-dawn';
    private const string CHOICE_VALUE = 'narwhal-grumpy';
    private const string TAG_VALUE = 'capybara-tag';

    /**
     * @return iterable<string, array{null|string, list<string>}> who looks (null = a guest), and at which pages -
     *                                                             `{time}`, `{a}`, `{b}`, `{puzzle}` are filled in
     */
    public static function provideViewers(): iterable
    {
        $shared = [
            '/en/result/{time}',
            'frame:/en/result/{time}',
            '/en/puzzle/{puzzle}',
            '/en/player-profile/{a}',
            '/en/player-profile/{b}',
            '/en/recent-activity',
        ];

        yield 'the pair partner' => [self::B, [
            ...$shared,
            // Their own values form of the shared result, their edit form of it, their own pages
            '/en/custom-fields/result/{time}',
            'frame:/en/custom-fields/result/{time}',
            '/en/edit-time/{time}',
            'frame:/en/edit-time/{time}',
            '/en/time-added/{time}',
            '/en/custom-fields',
            '/en/puzzle-add',
            '/en/puzzle-add/{puzzle}',
            '/en/my-profile',
            '/en/export-puzzler-data/{b}/download/json',
            '/en/export-puzzler-data/{b}/download/csv',
            '/en/export-puzzler-data/{b}/download/xml',
        ]];
        yield 'another player' => [self::C, [...$shared, '/en/custom-fields', '/en/puzzle-add/{puzzle}']];
        yield 'a guest' => [null, $shared];
    }

    /**
     * @param list<string> $pages
     */
    #[DataProvider('provideViewers')]
    public function testNothingAnybodyElseOpensCarriesThePlayersOwnValues(null|string $viewer, array $pages): void
    {
        $browser = self::createClient();
        [$timeId, $secrets] = $this->pairResultWithValuesOfA();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        }

        foreach ($pages as $page) {
            $inFrame = str_starts_with($page, 'frame:');
            $url = strtr($inFrame ? substr($page, 6) : $page, ['{time}' => $timeId, '{a}' => self::A, '{b}' => self::B, '{puzzle}' => CustomFieldsScenario::PUZZLE]);

            $browser->followRedirects();
            $browser->request('GET', $url, server: $inFrame ? ['HTTP_TURBO_FRAME' => 'modal-frame'] : []);
            $browser->followRedirects(false);

            self::assertResponseIsSuccessful($page);
            $this->assertCarriesNothingOfA((string) $browser->getInternalResponse()->getContent(), $secrets, $page);
        }
    }

    public function testTheApiCarriesNothingOfIt(): void
    {
        $browser = self::createClient();
        [$timeId, $secrets] = $this->pairResultWithValuesOfA();

        // The partner's token and the owner's own token alike: the API does not know custom fields at all
        foreach ([self::B, self::A] as $playerId) {
            PatTestHelper::addBearerToken($browser, PatTestHelper::createToken($browser, $playerId));

            foreach (['/api/v1/me', '/api/v1/me/results', '/api/v1/me/results?type=duo', '/api/v1/me/statistics', '/api/v1/me/library', '/api/v1/puzzles/' . CustomFieldsScenario::PUZZLE] as $url) {
                $browser->request('GET', $url);

                self::assertResponseIsSuccessful($url);
                $content = (string) $browser->getResponse()->getContent();
                $this->assertCarriesNothingOfA($content, $secrets, $url);
                self::assertStringNotContainsStringIgnoringCase('custom', $content, $url);
            }

            $browser->request('GET', '/api/v1/me/results?type=duo');
            self::assertStringContainsString($timeId, (string) $browser->getResponse()->getContent(), 'The shared result itself is in both players\' results');
        }
    }

    public function testTheOwnerDoesSeeThem(): void
    {
        // What the canary looks for is really on the owner's page - otherwise it would prove nothing
        $browser = self::createClient();
        [$timeId] = $this->pairResultWithValuesOfA();
        TestingLogin::asPlayer($browser, self::A);

        $browser->request('GET', '/en/result/' . $timeId);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();

        foreach ([self::FIELD_NAME, self::CHOICE_FIELD_NAME, self::TEXT_VALUE, self::CHOICE_VALUE, self::TAG_VALUE] as $own) {
            self::assertStringContainsString($own, $content);
        }
    }

    /**
     * @return array{string, list<string>} the shared result, and everything of A's that must stay A's
     */
    private function pairResultWithValuesOfA(): array
    {
        $scenario = new CustomFieldsScenario(self::getContainer());
        $notes = $scenario->field(self::A, self::FIELD_NAME);
        $mood = $scenario->field(self::A, self::CHOICE_FIELD_NAME, CustomFieldType::Choice, [self::CHOICE_VALUE, 'narwhal-cheerful']);
        $tags = $scenario->field(self::A, 'Pangolin labels', CustomFieldType::Tags);
        // B has a field of their own, with nothing in it
        $scenario->field(self::B, 'Plain notes');

        $timeId = $scenario->result(self::A_USER, ['#player3']);
        $scenario->setValues($timeId, self::A, [$notes => self::TEXT_VALUE, $mood => self::CHOICE_VALUE, $tags => self::TAG_VALUE]);

        return [$timeId, [
            self::FIELD_NAME, self::CHOICE_FIELD_NAME, 'Pangolin labels',
            self::TEXT_VALUE, self::CHOICE_VALUE, 'narwhal-cheerful', self::TAG_VALUE,
            $notes, $mood, $tags,
        ]];
    }

    /**
     * @param list<string> $secrets
     */
    private function assertCarriesNothingOfA(string $content, array $secrets, string $where): void
    {
        self::assertNotSame('', $content, $where);

        foreach ($secrets as $secret) {
            self::assertStringNotContainsString($secret, $content, $where . ' carries "' . $secret . '"');
        }
    }
}
