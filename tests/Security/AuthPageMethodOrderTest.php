<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use SpeedPuzzling\Web\Tests\ConfiguresSocialLoginProviders;
use SpeedPuzzling\Web\Value\OauthProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Order of the ways in (docs/features/auth-ux-redesign.md §10):
 * - /login is EMAIL FIRST - every existing account has a password, social
 *   sign-in is the newcomer - with the providers under "or continue with";
 * - /register lists "Continue with email" first among equal-weight buttons
 *   and opens the form in place; the form is rendered open whenever hiding it
 *   would hide something (?method=email, a submitted form, no providers).
 */
final class AuthPageMethodOrderTest extends WebTestCase
{
    use ConfiguresSocialLoginProviders;

    private const string INSTAGRAM_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22F76 Instagram 385.0.0.28.93 (iPhone15,3; iOS 18_5; en_US; en; scale=3.00; 1290x2796; 745621391; IABMV/1)';

    protected function tearDown(): void
    {
        $this->restoreSocialLoginEnv();

        parent::tearDown();
    }

    public function testLoginPutsTheEmailFormBeforeTheProviders(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple);
        $browser = self::createClient();

        $browser->request('GET', '/login', server: ['HTTP_USER_AGENT' => self::INSTAGRAM_IOS]);
        self::assertResponseIsSuccessful();

        $html = (string) $browser->getResponse()->getContent();
        $notice = strpos($html, 'id="in-app-browser-notice"');
        $form = strpos($html, 'id="login-form"');
        $codeInstead = strpos($html, 'href="/login-link"');
        $divider = strpos($html, 'or continue with');
        $google = strpos($html, 'btn-google-signin');
        $hint = strpos($html, 'class="auth-social-hint"');

        self::assertNotFalse($notice);
        self::assertNotFalse($form);
        self::assertNotFalse($codeInstead);
        self::assertNotFalse($divider);
        self::assertNotFalse($google);
        self::assertNotFalse($hint);

        // In-app notice on top, then the form, the code, the divider, the providers, the hint
        self::assertLessThan($form, $notice);
        self::assertLessThan($codeInstead, $form);
        self::assertLessThan($divider, $codeInstead);
        self::assertLessThan($google, $divider);
        self::assertLessThan($hint, $google);
    }

    public function testLoginWithoutProvidersHasNoDivider(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple, OauthProvider::Facebook);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/login');

        self::assertCount(1, $crawler->filter('#login-form'));
        self::assertCount(0, $crawler->filter('.auth-divider'));
        self::assertCount(0, $crawler->filter('.auth-social'));
    }

    public function testRegisterOffersContinueWithEmailFirstAndKeepsTheFormClosed(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $trigger = $crawler->filter('a.btn-email-signin');
        self::assertCount(1, $trigger);
        // Without JavaScript the button is a plain link to the open form
        self::assertSame('/register?method=email', $trigger->attr('href'));
        self::assertNull($trigger->attr('hidden'));
        self::assertSame('false', $trigger->attr('aria-expanded'));
        self::assertSame('register-email', $trigger->attr('aria-controls'));

        self::assertNotNull($crawler->filter('#register-email')->attr('hidden'));

        $html = (string) $browser->getResponse()->getContent();
        $email = strpos($html, 'btn-email-signin');
        $form = strpos($html, 'id="register-email"');
        $google = strpos($html, 'btn-google-signin');
        self::assertNotFalse($email);
        self::assertNotFalse($form);
        self::assertNotFalse($google);

        // "Continue with email" first, its form right under it, the providers below
        self::assertLessThan($form, $email);
        self::assertLessThan($google, $form);
    }

    public function testRegisterRendersTheFormOpenForMethodEmail(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/register?method=email&return=/en/puzzle');

        self::assertNull($crawler->filter('#register-email')->attr('hidden'));
        self::assertNotNull($crawler->filter('a.btn-email-signin')->attr('hidden'));
        self::assertCount(1, $crawler->filter('#register-email input[type="email"]'));
        // The providers stay available below the open form
        self::assertCount(1, $crawler->filter('.btn-google-signin'));
        self::assertStringContainsString('or continue with', $crawler->filter('#register-email')->text());
    }

    public function testRegisterRendersTheFormOpenWhenItComesBackWithErrors(): void
    {
        $this->enableSocialLoginProvider(OauthProvider::Google);
        $browser = self::createClient();
        $browser->setServerParameter('REMOTE_ADDR', sprintf('198.51.100.%d', random_int(1, 254)));

        $crawler = $browser->request('GET', '/register');
        $form = $crawler->selectButton('Create account')->form();
        $crawler = $browser->submit($form, [
            $form->getName() . '[email]' => sprintf('open.form+%s@example.com', bin2hex(random_bytes(4))),
            $form->getName() . '[plainPassword]' => 'short',
        ]);

        // A validation error must never sit inside a closed form
        self::assertResponseStatusCodeSame(422);
        self::assertNull($crawler->filter('#register-email')->attr('hidden'));
        self::assertNotNull($crawler->filter('a.btn-email-signin')->attr('hidden'));
    }

    public function testRegisterWithoutProvidersShowsTheFormStraightAway(): void
    {
        $this->disableSocialLoginProvider(OauthProvider::Google, OauthProvider::Apple, OauthProvider::Facebook);
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/register');

        // Nothing to choose between - no extra tap
        self::assertCount(0, $crawler->filter('a.btn-email-signin'));
        self::assertNull($crawler->filter('#register-email')->attr('hidden'));
        self::assertCount(0, $crawler->filter('.auth-divider'));
    }
}
