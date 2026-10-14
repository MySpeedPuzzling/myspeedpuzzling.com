<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SpeedPuzzling\Web\Services\NewsletterTokenSigner;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\NewsletterAudience;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * Every checkbox switch is a real switch (docs/features/navigation README §4.13 G10, requirement GEST-10): the
 * `switch` attribute (+ role="switch") on every `form-switch` checkbox - in the templates, in the participants sheet's
 * person editor and through the project form theme (templates/form/msp_theme.html.twig: a checkbox whose label class is
 * `checkbox-switch`). iOS Safari plays its own haptic when the user toggles one; under Bootstrap's appearance: none it
 * looks exactly as before (the screenshots of checks/GEST-10.py). Also the Settings page's Vibration switch.
 */
final class SwitchAttributeTest extends WebTestCase
{
    private const string SWITCH_INPUT = '.form-switch input[type="checkbox"]';

    public function testEveryRenderedSwitchOnTheSettingsPageIsARealSwitch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');

        self::assertResponseIsSuccessful();
        $this->assertRealSwitches($crawler, 1);

        // Settings › Preferences › Vibration: per device (localStorage), on by default, shown only on Android phones
        $card = $crawler->filter('#preferences');
        self::assertCount(1, $card);
        self::assertStringContainsString('os-android-only', (string) $card->attr('class'));
        self::assertSame('haptics-setting', $card->attr('data-controller'));
        self::assertSame('Preferences', trim($card->filter('h3')->text()));

        $vibration = $card->filter('input#haptics-setting');
        self::assertCount(1, $vibration);
        self::assertNotNull($vibration->attr('checked'), 'on by default');
        self::assertNull($vibration->attr('name'), 'never sent to the server');
        self::assertSame('switch', $vibration->attr('data-haptics-setting-target'));
        self::assertSame('haptics-setting#change', $vibration->attr('data-action'));
        self::assertSame('Vibration', trim($card->filter('label[for="haptics-setting"]')->text()));
        self::assertSame('haptics-setting-help', $vibration->attr('aria-describedby'));
        self::assertStringContainsString('Your phone\'s own vibration settings still apply.', $card->filter('#haptics-setting-help')->text());
    }

    /**
     * The Vibration switch only on the web (Platform feel review F4): inside the Hotwire Native app no haptic plays until a
     * bridge component exists (README §4.14 (c)) - the card would promise vibrations that never come. Phone browsers keep it
     */
    public function testTheVibrationSwitchIsNotRenderedInsideTheNativeApp(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        foreach (NativeUserAgents::allNative() as $name => [$userAgent]) {
            $crawler = $browser->request('GET', '/en/edit-profile', server: ['HTTP_USER_AGENT' => $userAgent]);

            self::assertResponseIsSuccessful($name);
            self::assertCount(0, $crawler->filter('#preferences'), $name);
            self::assertCount(0, $crawler->filter('#haptics-setting'), $name);
            self::assertCount(0, $crawler->filter('[data-controller~="haptics-setting"]'), $name);
            // The page's other settings stay
            self::assertCount(1, $crawler->filter('#emails'), $name);
        }

        foreach (NativeUserAgents::web() as $name => [$userAgent]) {
            $crawler = $browser->request('GET', '/en/edit-profile', server: ['HTTP_USER_AGENT' => $userAgent]);

            self::assertResponseIsSuccessful($name);
            self::assertCount(1, $crawler->filter('#preferences.os-android-only input#haptics-setting'), $name);
        }
    }

    public function testEveryRenderedSwitchOnTheEmailPreferencesPageIsARealSwitch(): void
    {
        $browser = self::createClient();
        /** @var NewsletterTokenSigner $signer */
        $signer = self::getContainer()->get(NewsletterTokenSigner::class);
        $token = $signer->generatePreferencesToken(NewsletterAudience::Player, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR_EMAIL);

        $crawler = $browser->request('GET', '/en/email-preferences/' . $token);

        self::assertResponseIsSuccessful();
        $this->assertRealSwitches($crawler, 3);
    }

    /** A Symfony form page: the add form's first-try and unboxed checkboxes come through the project form theme */
    public function testEveryRenderedSwitchOnTheAddFormIsARealSwitch(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/puzzle-add');

        self::assertResponseIsSuccessful();
        $this->assertRealSwitches($crawler, 3);

        foreach (['first_attempt', 'unboxed'] as $field) {
            $input = $crawler->filter('input[type="checkbox"][name$="[' . $this->formName($field) . ']"]');
            self::assertCount(1, $input, $field);
            self::assertNotNull($input->attr('switch'), $field . ' is a real switch');
            self::assertSame('switch', $input->attr('role'), $field);
            self::assertCount(1, $input->closest('.form-check.form-switch') ?? new Crawler(), $field . ': Bootstrap\'s form-switch wrapper from the theme');
        }

        // "Time saved": the haptic of the form's successful submit (decision 61)
        self::assertSame('confirm', $crawler->filter('form[data-haptic-on-success]')->attr('data-haptic-on-success'));
    }

    /** The project form theme: `checkbox-switch` → `switch` + role; any other checkbox stays a checkbox */
    public function testTheFormThemeTurnsACheckboxSwitchIntoARealSwitch(): void
    {
        self::bootKernel();
        /** @var FormFactoryInterface $factory */
        $factory = self::getContainer()->get('form.factory');
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $form = $factory->createNamedBuilder('theme_probe', options: ['csrf_protection' => false])
            ->add('asSwitch', CheckboxType::class, ['required' => false, 'label_attr' => ['class' => 'checkbox-switch']])
            ->add('asSwitchWithOwnRole', CheckboxType::class, ['required' => false, 'label_attr' => ['class' => 'checkbox-switch'], 'attr' => ['role' => 'checkbox']])
            ->add('plain', CheckboxType::class, ['required' => false])
            ->getForm()
            ->createView();

        $html = $twig->createTemplate('{{ form_widget(form) }}')->render(['form' => $form]);
        $crawler = new Crawler($html);

        $switch = $crawler->filter('#theme_probe_asSwitch');
        self::assertSame('switch', $switch->attr('switch'));
        self::assertSame('switch', $switch->attr('role'));
        self::assertCount(1, $switch->closest('.form-check.form-switch') ?? new Crawler());

        // An explicit role is kept
        self::assertSame('checkbox', $crawler->filter('#theme_probe_asSwitchWithOwnRole')->attr('role'));
        self::assertNotNull($crawler->filter('#theme_probe_asSwitchWithOwnRole')->attr('switch'));

        $plain = $crawler->filter('#theme_probe_plain');
        self::assertNull($plain->attr('switch'));
        self::assertNull($plain->attr('role'));
        self::assertCount(0, $plain->closest('.form-switch') ?? new Crawler());
    }

    /**
     * The grep count of the requirement: the `form-switch` checkboxes in templates/ (and the participants sheet's person
     * editor) = those with `switch`. A form-switch wrapping a form_row() / form_widget() passes the label class
     * `checkbox-switch`, so the theme makes its checkbox a real switch.
     */
    public function testEveryFormSwitchCheckboxInTheSourcesCarriesTheSwitchAttribute(): void
    {
        $inputs = 0;
        $withSwitch = 0;
        $themed = 0;
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            preg_match_all('~class="[^"]*\bform-switch\b[^"]*"~', $code, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$class, $offset]) {
                $after = substr($code, $offset + strlen($class), 1200);
                $input = preg_match('~<input\b[^>]*>~s', $after, $inputMatch, PREG_OFFSET_CAPTURE) === 1 ? $inputMatch[0] : null;
                $call = preg_match('~form_(?:row|widget)\([^)]*\)~s', $after, $callMatch, PREG_OFFSET_CAPTURE) === 1 ? $callMatch[0] : null;

                if ($call !== null && ($input === null || $call[1] < $input[1])) {
                    $themed++;

                    if (!str_contains($call[0], 'checkbox-switch')) {
                        $offenders[] = $path . ': a form-switch around ' . $call[0] . ' without the checkbox-switch label class';
                    }

                    continue;
                }

                self::assertNotNull($input, $path . ': a form-switch without its checkbox');
                $tag = $input[0];

                if (!str_contains($tag, 'type="checkbox"')) {
                    continue;
                }

                $inputs++;

                if (preg_match('~\sswitch(?=[\s>/])~', $tag) === 1 && str_contains($tag, 'role="switch"')) {
                    $withSwitch++;
                } else {
                    $offenders[] = $path . ': ' . preg_replace('~\s+~', ' ', $tag);
                }
            }
        }

        self::assertSame([], $offenders, 'Every form-switch checkbox is a real switch: `switch` + role="switch" (README §4.13 G10)');
        self::assertSame($inputs, $withSwitch);
        // The inventory of GEST-10 (24 in 11 templates at planning) plus what main added since: the online round review,
        // the Results filters (one input per switch of its loop), the Settings page's Vibration switch, the participants
        // sheet's round toggles - fewer means a template lost its switches, more is fine
        self::assertGreaterThanOrEqual(25, $inputs);
        self::assertSame(2, $themed, 'the add form\'s first-try and unboxed rows');
    }

    private function assertRealSwitches(Crawler $crawler, int $atLeast): void
    {
        $switches = $crawler->filter(self::SWITCH_INPUT);
        self::assertGreaterThanOrEqual($atLeast, $switches->count());

        foreach ($switches as $input) {
            self::assertInstanceOf(\DOMElement::class, $input);
            $id = $input->getAttribute('id');
            self::assertTrue($input->hasAttribute('switch'), $id . ' is a real switch');
            self::assertSame('switch', $input->getAttribute('role'), $id);
        }
    }

    /** The add form's field names (PuzzleAddFormType: firstAttempt, unboxed) */
    private function formName(string $field): string
    {
        return match ($field) {
            'first_attempt' => 'firstAttempt',
            default => $field,
        };
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates', RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        $files['assets/participants_sheet/views/person_editor.js'] = (string) file_get_contents($root . '/assets/participants_sheet/views/person_editor.js');
        ksort($files);

        return $files;
    }
}
