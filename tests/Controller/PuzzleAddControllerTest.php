<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleAddControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirected(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessForm(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/puzzle-add');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[name="puzzle_add_form"]');
    }

    /**
     * @return array<string, array{null|string}>
     */
    public static function provideInvalidPuzzleValues(): array
    {
        return [
            // A disabled input on the client is excluded from the submit entirely
            'puzzle field missing from request' => [null],
            'puzzle field empty' => [''],
        ];
    }

    /**
     * Regression test: submitting without a puzzle must produce a validation
     * error, not a TypeError when constructing the AddPuzzleSolvingTime message.
     */
    #[DataProvider('provideInvalidPuzzleValues')]
    public function testSubmitWithoutPuzzleShowsValidationError(null|string $puzzle): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');
        $this->assertResponseIsSuccessful();

        $csrfToken = $crawler->filter('input[name="puzzle_add_form[_token]"]')->attr('value');
        self::assertNotNull($csrfToken);

        $formData = [
            '_token' => $csrfToken,
            'mode' => 'speed_puzzling',
            'brand' => ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            'timeHours' => '1',
            'timeMinutes' => '7',
            'timeSeconds' => '0',
            'finishedAt' => '12.07.2026',
            'firstAttempt' => '1',
            'collection' => '__system_collection__',
        ];

        if ($puzzle !== null) {
            $formData['puzzle'] = $puzzle;
        }

        $browser->request('POST', '/en/puzzle-add', [
            'puzzle_add_form' => $formData,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('form[name="puzzle_add_form"]', 'This field is required!');
    }
}
