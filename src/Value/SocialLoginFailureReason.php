<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a social sign-in failed. The value is the machine code recorded in the
 * login_failure audit row (`metadata.code`) and in the log line; messageKey()
 * is what the visitor reads on /login (a `security` domain translation key,
 * the English sentence itself - see translations/security.*.yml).
 *
 * Only what is safe to reveal gets its own copy. A refused auto-link gets the
 * generic "not possible" message on purpose: naming the reason would reveal
 * which sign-in methods the account has. (The admin-only codes
 * `admin_only_denied` / `admin_only_registration_disabled` left with the
 * admin-only stage on 2026-09-29; older audit rows may still carry them.)
 */
enum SocialLoginFailureReason: string
{
    public const string MESSAGE_CANCELLED = 'You cancelled signing in with %provider%. Nothing was changed.';
    public const string MESSAGE_STATE_INVALID = 'Signing in with %provider% took too long or was opened twice. Please try again.';
    public const string MESSAGE_PROVIDER_FAILED = 'Signing in with %provider% didn\'t work. Please try again, or sign in with your e-mail.';
    public const string MESSAGE_NOT_POSSIBLE = 'We couldn\'t sign you in with %provider%. Please sign in with your e-mail or password, then connect %provider% in your profile settings.';

    // Rule 3, split by which side has not verified the address. The account side
    // is only named when the provider vouched for the address, i.e. to someone who
    // demonstrably owns that mailbox.
    public const string MESSAGE_ACCOUNT_EMAIL_UNVERIFIED = 'There is already an account with this email address, but that address has not been verified yet. To be sure we connect the right accounts, please sign in to that account first (with your password or an emailed sign-in link), then connect %provider% in your profile settings under Connected sign-in methods.';
    public const string MESSAGE_PROVIDER_EMAIL_UNVERIFIED = 'There is already an account with this email address, but %provider% has not confirmed that the address belongs to you. To be sure we connect the right accounts, please sign in to that account first (with your password or an emailed sign-in link), then connect %provider% in your profile settings under Connected sign-in methods.';
    // Facebook re-asks for a declined email permission (auth_type=rerequest,
    // SocialLoginProviders::authorizationOptions()), so "try again" really helps
    public const string MESSAGE_NO_EMAIL = '%provider% did not share an email address with us, so we cannot sign you in this way. Please try again and allow access to your email address when %provider% asks - or sign in another way.';

    // The OAuth state was missing, expired, replayed or belonged to another flow
    case StateInvalid = 'state_invalid';
    // The visitor pressed cancel on the provider's consent screen
    case ProviderCancelled = 'provider_cancelled';
    // Any other `error` the provider sent back
    case ProviderError = 'provider_error';
    case CodeMissing = 'code_missing';
    case CodeExchangeFailed = 'code_exchange_failed';
    case AutoLinkRefused = 'auto_link_refused';
    case AccountEmailUnverified = 'account_email_unverified';
    case ProviderEmailUnverified = 'provider_email_unverified';
    case NoEmail = 'no_email';

    public function messageKey(): string
    {
        return match ($this) {
            self::StateInvalid => self::MESSAGE_STATE_INVALID,
            self::ProviderCancelled => self::MESSAGE_CANCELLED,
            self::ProviderError, self::CodeMissing, self::CodeExchangeFailed => self::MESSAGE_PROVIDER_FAILED,
            self::AutoLinkRefused => self::MESSAGE_NOT_POSSIBLE,
            self::AccountEmailUnverified => self::MESSAGE_ACCOUNT_EMAIL_UNVERIFIED,
            self::ProviderEmailUnverified => self::MESSAGE_PROVIDER_EMAIL_UNVERIFIED,
            self::NoEmail => self::MESSAGE_NO_EMAIL,
        };
    }

    /**
     * The provider's `error` parameter on the callback. `access_denied` is the
     * OAuth 2 standard (Google, Facebook); Apple's form_post sends
     * `user_cancelled_authorize`.
     */
    public static function fromProviderError(string $error): self
    {
        return match ($error) {
            'access_denied', 'user_cancelled_authorize' => self::ProviderCancelled,
            default => self::ProviderError,
        };
    }
}
