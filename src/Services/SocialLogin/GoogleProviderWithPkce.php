<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

use League\OAuth2\Client\Provider\Google;

/**
 * Google with PKCE (S256) on top of the client secret.
 *
 * league/oauth2-client reads the PKCE method from getPkceMethod(), which
 * returns null in AbstractProvider - only GenericProvider honours a
 * `pkceMethod` constructor option, the Google provider silently ignores it.
 * With a method set, getAuthorizationUrl() mints the verifier (read it with
 * getPkceCode() AFTER that call, it lands in OauthFlowState) and adds
 * code_challenge + code_challenge_method; on the callback setPkceCode() puts
 * the verifier back and getAccessToken() sends it as code_verifier.
 *
 * Facebook: Meta documents PKCE only for its OIDC flow (scope `openid` +
 * nonce), not the classic Graph login we run - left off on purpose.
 * Apple: its web flow does not document PKCE - left off.
 */
final class GoogleProviderWithPkce extends Google
{
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
