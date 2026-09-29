# Social login setup — Facebook (Meta)

Exact click-path for "Continue with Facebook" in today's (2026) **use-case based** Meta developer dashboard, plus the secrets hand-off and the go-live checklist. The code is done and deployed dark (auth hardening PR 2 #175, hardening 2026-09-29). Google and Apple went public on 2026-09-29 and lost their flags together with the admin-only stage; **Facebook keeps `SOCIAL_LOGIN_FACEBOOK_ENABLED` until the Meta app is published** - and since there is no admin-only stage any more, turning the flag on is the public launch.

## Read this first

- **Instagram sign-in does not exist for us.** The Instagram Basic Display API was shut down in December 2024; "Instagram Login" is for business/creator accounts only and returns no e-mail. Facebook Login is Meta's consumer sign-in, so the button says **"Continue with Facebook"** with a small hint *"Meta account - also for Instagram users"*.
- **Create the app once and never recreate it.** Facebook gives every user an *app-scoped* ID - a different number per app. We store that ID (`oauth_identity.provider_user_id`); a new app = new IDs = every Facebook-linked player loses their sign-in. Rotating the **secret** is harmless; deleting/recreating the **app** is not.
- Permissions we ask for: `public_profile` + `email` only. Both are usable by everyone without App Review.
- We trust the e-mail Facebook gives us (Graph only returns confirmed addresses). A user who unticks the e-mail permission is refused with "…did not share an email address… try again and allow access". Because we send `auth_type=rerequest`, trying again **does** show the e-mail question again (without it Facebook never re-asks).
- Graph API version: `v26.0` (`SocialLoginProviders::FACEBOOK_GRAPH_API_VERSION`). Released 2026-07-29; Meta keeps each version at least 2 years, so it is safe until **July 2028 at the earliest** (the exact date appears on <https://developers.facebook.com/docs/graph-api/changelog/versions/> once v27 ships). Meta e-mails the app admins before a version expires — then bump the constant (one line).

## Values you will need

| What | Value |
|---|---|
| Valid OAuth Redirect URI | `https://myspeedpuzzling.com/login/social/facebook/callback` (route `social_login_callback`; login *and* "connect from settings" both come back here) |
| App domain | `myspeedpuzzling.com` |
| Privacy policy URL | `https://myspeedpuzzling.com/en/privacy-policy` |
| Terms of Service URL | `https://myspeedpuzzling.com/en/terms-of-service` |
| Data deletion instructions URL | `https://myspeedpuzzling.com/en/data-deletion` (`DataDeletionController`) |
| Contact e-mail | `jan@myspeedpuzzling.com` |
| App icon | square PNG **1024 × 1024** (the repo has only a 512 px icon - export the logo at 1024) |
| Env vars the app reads | `FACEBOOK_APP_ID`, `FACEBOOK_APP_SECRET`, `SOCIAL_LOGIN_FACEBOOK_ENABLED` - Facebook is available iff the flag is on **and** both credentials are set (`SocialLoginSettings`) |
| Local dev | nothing to set up: while an app is in Development mode Meta allows `localhost` redirect URIs automatically |

## 1. Create the app (Jan, ~10 min)

1. Open <https://developers.facebook.com/apps/>, logged in as the Facebook account that is admin of the MySpeedPuzzling Facebook page. If asked, register as a developer (phone/e-mail confirmation).
2. Click **Create app**. The wizard has five steps:
   1. **App details** - App name `MySpeedPuzzling`, App contact email `jan@myspeedpuzzling.com` → **Next**.
   2. **Use cases** - tick **"Authenticate and request data from users with Facebook Login"** (filter "All" if you do not see it). Nothing else. → **Next**.
      Do *not* pick "Facebook Login for Business" or any Pages/Marketing/Instagram use case.
   3. **Business** - choose **"I don't want to connect a business portfolio yet."** → **Next**. (Connecting the portfolio that owns the Facebook page is also fine; not needed for our two permissions.)
   4. **Requirements** - informational → **Next**.
   5. **Overview** - check and click **Create app** (asks for your Facebook password).
3. You land on the app **Dashboard**. The app is now in **Development** mode (unpublished) - only people with a role on the app can sign in. That is fine for now.

## 2. Use case → permissions + redirect URI

Left menu **Use cases** → on the "Authenticate and request data from users with Facebook Login" row click **Customize**.

**Permissions** tab:

- `public_profile` - already added.
- `email` - click **Add**. Both rows must end up added (status "Ready for testing"; "Advanced access" once Live). If the access level column shows *Standard access*, switch it to **Advanced access** - consumer apps are pre-approved for these two.

**Settings** tab (label may read *Go to settings* / *Facebook Login settings*), section **Client OAuth settings**:

| Setting | Value |
|---|---|
| Client OAuth login | **Yes** |
| Web OAuth login | **Yes** |
| Enforce HTTPS | **Yes** |
| Force Web OAuth reauthentication | No |
| Embedded browser OAuth login | No |
| Use Strict Mode for redirect URIs | **Yes** (if the toggle is shown; newer apps have it always on) |
| Valid OAuth Redirect URIs | `https://myspeedpuzzling.com/login/social/facebook/callback` - exactly this, nothing else |
| Login from Devices | No |
| Login with the JavaScript SDK | No (we use the server-side redirect flow) |

Click **Save changes** at the bottom. (There is a "Redirect URI Validator" box on the same page - paste the URL there to double-check it says valid.)

## 3. App settings → Basic

Left menu **App settings → Basic**:

- **App ID** - shown at the top (a number). You will copy it in §6.
- **App secret** - `●●●●●●` → **Show** asks for your Facebook password again. You will copy it in §6.
- **Display name**: `MySpeedPuzzling`
- **App domains**: `myspeedpuzzling.com`
- **Contact email**: `jan@myspeedpuzzling.com`
- **Privacy policy URL**: `https://myspeedpuzzling.com/en/privacy-policy`
- **Terms of Service URL**: `https://myspeedpuzzling.com/en/terms-of-service`
- **User data deletion**: in the dropdown pick **Data deletion instructions URL** and enter `https://myspeedpuzzling.com/en/data-deletion`
- **App icon (1024 x 1024)**: upload the icon.
- **Category**: *Entertainment* (or *Lifestyle*).
- Bottom of the page: **+ Add platform** → **Website** → Site URL `https://myspeedpuzzling.com/` (only if the page asks for a platform).

**Save changes.**

## 4. Publish (go Live)

Left menu **Publish** (older layouts: the *App mode* toggle Development → Live at the top). The page lists what is still missing - normally only the §3 fields. When everything is ticked, click **Publish**.

- **Business verification is not expected** for `email` + `public_profile`. If Meta nevertheless insists on it before publishing (Meta's docs say advanced access "may" need a verified business), **stop and tell Claude** - that is a decision (MySpeedPuzzling business documents), not a click.
- Being Live does *not* turn anything on in MySpeedPuzzling - the feature flag does (§7).

## 5. Keep it healthy (yearly)

- **Data Use Checkup**: once a year Meta e-mails the app admins and shows a banner under **Required actions** in the dashboard. Answer it (we use `email` + `public_profile` to create/sign in the account; no data shared with third parties). If it is ignored, Meta restricts the app → Facebook sign-in stops working.
- **Graph API version expiry**: when Meta e-mails that `v26.0` is expiring, ask Claude to bump `SocialLoginProviders::FACEBOOK_GRAPH_API_VERSION`.
- Keep a second admin on the app (**App roles → Roles → Add people**) so the app is not lost with one Facebook account.

## 6. Secrets hand-off (Jan → Claude)

1. In **App settings → Basic** copy the **App ID** and (after **Show** + password) the **App secret**.
2. Put them into `~/.msp-secrets/social-login.env` - a plain `KEY=value` file **outside any git repository** (create the folder if it does not exist; never commit it, never paste the values into chat):
   ```
   FACEBOOK_APP_ID=1234567890123456
   FACEBOOK_APP_SECRET=0123456789abcdef0123456789abcdef
   ```
3. Tell Claude "Facebook secrets are in ~/.msp-secrets/social-login.env".

What the agent then does (procedure: memory `reference_production_access.md`, "Infisical admin from the box"):

1. Writes `FACEBOOK_APP_ID` and `FACEBOOK_APP_SECRET` to Infisical project **myspeedpuzzling**, environment **prod**, path `/` - **before** the flag changes.
2. **Only once the Meta app is published (§4)** sets `SOCIAL_LOGIN_FACEBOOK_ENABLED=1` in the same place - that is the public launch, the button shows for everybody right away. While the app is still in development mode the flag stays `0`: only the app's admins/testers could sign in, everybody else would hit a Facebook error.
3. Queues a deploy (`/srv/deploy/queue/myspeedpuzzling.<epoch>.<rand>.job`, `app=myspeedpuzzling` / `tag=main`) so `dump_secrets` renders the new `.env`, and checks the web container sees the values.
4. The file can stay as your local copy or be deleted once Infisical holds the values (Jan's call).

## 7. Test checklist (right after the flag flip)

The buttons are public from the flip on, so run this straight away. Sign in with your own account.

0. **Buttons**: `/login` and `/register` show "Continue with Facebook" with the Meta/Instagram hint.

1. **Connect from settings**: Edit profile → *Connected sign-in methods* → **Continue with Facebook** → Facebook consent → back on edit profile with "Connected!". The Facebook row appears; you get the "new sign-in method linked" notice e-mail.
2. **Sign in**: sign out, open `https://myspeedpuzzling.com/login/social/facebook` → you are signed in to the same account.
3. **Declined e-mail, then retry**: Disconnect Facebook again (step 5), and in Facebook → Settings → *Apps and websites* remove MySpeedPuzzling so the consent dialog shows. Open `/login/social/facebook`, click **Edit access**, untick *Email address*, continue → back on /login with "Facebook did not share an email address… try again and allow access". Now start `/login/social/facebook` again → **Facebook asks for the e-mail again** - that is the point of `auth_type=rerequest`. Allow it → you are signed in (the Facebook e-mail equals your verified e-mail → auto-link) or, if the e-mails differ, you get "sign in with your password first, then connect".
4. **Cancel**: start `/login/social/facebook` and press **Cancel** / close on Facebook → back on /login with a generic "sign-in failed", nothing created. Same from settings → "Connection cancelled — nothing changed."
5. **Disconnect**: Edit profile → Disconnect Facebook (only possible when the account has a password or another method).
6. Reconnect from settings (step 1) so your account ends the test linked; the app-scoped ID stays the same across removals, so it is the same identity.

## 8. After the flip

Sign in with a Facebook account that is new to MySpeedPuzzling end-to-end (new account via the "Create a new account?" page). Once Facebook has been stable for a while, ask Claude to remove the flag (`docs/features/feature_flags.md`) so Facebook follows the credentials rule like Google and Apple.

**Rollback**: `SOCIAL_LOGIN_FACEBOOK_ENABLED=0` in Infisical + redeploy. Buttons disappear, start/callback routes 404; linked identities stay in the database and work again when the flag returns. Players with no password can still sign in with the e-mailed sign-in link.

## Gotchas

- Some Facebook accounts have **no e-mail at all** (phone-only sign-ups). They get the "did not share an email" refusal every time; the rescue is the normal e-mail sign-in link or a Google/Apple account.
- A redirect URI mismatch shows Facebook's "URL blocked" page — the URI in §2 must match byte for byte (https, no trailing slash, no locale prefix).
- The secret is used only server-side for the code exchange. Rotating it (App settings → Basic → **Reset**) = update Infisical + redeploy; signed-in players are not affected.

Docs: [Manually build a login flow](https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow/) (incl. `auth_type=rerequest`), [Create an app](https://developers.facebook.com/docs/development/create-an-app/), [Access levels](https://developers.facebook.com/docs/graph-api/overview/access-levels/), [Graph API versions](https://developers.facebook.com/docs/graph-api/changelog/versions/).
