# Transactional e-mails

How every e-mail the app sends is built, and the rules a new one follows. Shipped 2026-10-02 (contrast, dark-mode
logo, plain-text part, preheaders, document semantics, bulletproof buttons, one-click unsubscribe of the digest).

## Anatomy

```twig
{% trans_default_domain 'emails' %}
{% apply inky_to_html|inline_css(source('@styles/foundation-emails.css'))|email_document %}
    <wrapper><container>
        {{ include('emails/_header.html.twig', {preheader: 'my_email.preheader'|trans}) }}
        <row><columns>…<button href="{{ url }}">{{ 'my_email.button'|trans }}</button>…</columns></row>
        {{ include('emails/_footer.html.twig') }}
    </container></wrapper>
{% endapply %}
```

- **Inky** (Foundation for Emails) → `inline_css` with `public/css/foundation-emails.css` (`@styles`) →
  **`email_document`** (`src/Twig/EmailDocumentTwigExtension.php`). Inline styles only - mail clients drop `<style>`
  except for the few head rules `email_document` adds.
- Texts in `translations/emails.{cs,de,en,es,fr,ja}.yml` - **all six locales** for every user-facing e-mail. Send
  with `->locale($locale)`: the body renderer then translates *and* `email_document` takes `lang` from it.
- Internal e-mails (`feedback`, `competition_submitted`, `oauth2_client_request`) skip the preheader.

## Header and footer

- `_header.html.twig`: one slim line - 32 px logo + bold "MySpeedPuzzling" as **one** link (a screen reader hears it
  once), left-aligned on a 2 px `#fe696a` rule. Jan chose it (2026-10-02); keep a small branded header.
- Logo = `public/img/email-logo.png`: 96 × 86 px (3× of 32 × 29), ~5 KB, with a ~1 px **white halo** so the
  dark-navy outline stays visible when Gmail iOS / Outlook darken the background. Generated from
  `speedpuzzling-logo.png` with Pillow (alpha dilated by a disk, white layer underneath, pngquant + oxipng). Always the
  absolute `https://myspeedpuzzling.com/img/email-logo.png` with `width="32" height="29"`.
- `_footer.html.twig`: 13 px for real (no `<small>`), `#6b7488`, centred, under a `#fe696a` rule.

## Preheader

The inbox preview line. `_header.html.twig` renders the optional `preheader` variable first, in a hidden `div.preheader`
(`display:none; max-height:0; overflow:hidden; mso-hide:all`) followed by `&#847;&zwnj;&nbsp;` filler, so the preview
does not run on into "MySpeedPuzzling" and the body. One key per e-mail (`<email>.preheader`), < ~90 characters,
specific (sign-in: the code + "or sign in with one tap"), never blaming the player. Not part of the text version.

## Colours (WCAG relative luminance, AA = 4.5:1 for text)

| Use | Colour | Contrast |
|---|---|---|
| Links, button background | `#d63c42` | 4.58:1 on white (4.54:1 on `#fefefe`) |
| Button text | `#ffffff` on `#d63c42` | 4.58:1 |
| Body text | `#4b566b` | 7.39:1 on white |
| Headings, header name | `#373f50` | 10.5:1 |
| Footer, `small`, `p.small-print` (notes, opt-out) | `#6b7488` | 4.69:1 on white |
| Newsletter footer on its `#f3f5f9` page | `#5f6a80` | 4.98:1 |
| Brand red `#fe696a` | header/footer rule, box accents only | 2.8:1 - never for text |

Links are **underlined**: by colour alone they differ from the body text by 1.6:1 (WCAG 1.4.1 asks 3:1). Buttons and
the header link are not.

## Buttons

Inky `<button href>` → `table.button > … > td > a`. The colour sits on the cell too (`background-color` in the CSS +
a `bgcolor` attribute that `email_document` adds to every cell with an inline background colour), `mso-padding-alt`
pads the cell in classic Outlook (it ignores the link's padding and background - without this it showed a thin strip),
`12 + 20 + 12 = 44 px` tap target, `border-radius` for modern clients (the inner table is `border-collapse: separate`,
otherwise the radius is ignored). `<button class="secondary">` = outlined (white, 2 px `#d63c42` border on the cell).
Buttons stay centred; body paragraphs are left-aligned like the header and title.

## `email_document`

Applied after `inline_css`; a `needs_context` filter, so templates only write `|email_document`:

- `<!DOCTYPE html>` instead of the HTML 4 doctype the inliner emits;
- `lang` + `dir="ltr"` on `<html>` **and** on a `<div role="article" aria-roledescription="email" aria-label="{subject}">`
  around the body (Gmail drops `<html>` attributes); locale = the translator's current locale (the mailer renders
  with the message's locale);
- `<title>` = subject (from the `email` context variable); omitted when rendered outside the mailer;
- viewport, `x-apple-disable-message-reformatting`, `format-detection` (no auto-linked phone numbers/dates/addresses),
  `color-scheme: light only`, `a[x-apple-data-detectors]` reset, and the mobile rules (container and columns 100 %);
- `role="presentation"` on every table without a role, `bgcolor` on cells with an inline background colour.

A document that already has a `<head>` is returned unchanged.

## Plain-text part

`src/Services/Email/EmailHtmlToTextConverter.php`, wired as `twig.mailer.html_to_text_converter` in
`config/packages/twig.php`. Symfony's default was `strip_tags()` - every URL gone, lines glued ("happy puzzling!Your
MySpeedPuzzling team"). In-house on PHP 8.5's HTML5 parser (`Dom\HTMLDocument`), no dependency:

- links `text (URL)` (just the URL when the text is the URL, the address for `mailto:`), buttons a paragraph of their
  own `Sign in: URL`, a linked logo next to a text link to the same URL writes nothing;
- paragraphs/headings/lists/tables keep their breaks, `<br>` = new line, `- ` list items;
- nothing hidden: `<head>`, `<style>`, the preheader, `display:none`, `mso-hide:all` (Inky's `size-sm` spacers too).

Tests: `tests/Services/Email/EmailHtmlToTextConverterTest.php` (rules) and `EmailTextPartTest.php` (real e-mails sent
through the mailer - every `href` of the HTML is in the text part).

## One-click unsubscribe (RFC 8058)

Subscription-like e-mails carry `List-Unsubscribe: <signed URL>` + `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
(Gmail/Yahoo bulk sender rules). The URL is signed with `UriSigner`, never expires, needs no sign-in:

| E-mail | URL service | Route | Switches off |
|---|---|---|---|
| "Your results" | `ResultEmailsUnsubscribeUrl` | `result_emails_unsubscribe` | `player.result_emails_enabled` |
| Unread messages digest | `DigestEmailsUnsubscribeUrl` | `digest_emails_unsubscribe` (`/{_locale}/message-emails/unsubscribe/{playerId}`) | `player.email_notifications_enabled` (gates only the digest) |

The controller: the mail client's POST with body `List-Unsubscribe=One-Click` → plain `200` (RFC 8058 forbids a
redirect), idempotent, no CSRF; GET only shows a page (mail scanners open links - they must not unsubscribe anybody)
whose button POSTs to the same URL and gets a `303`; unsigned/tampered → 404; `no-store`. The change goes through a
Messenger message + handler. The newsletter has its own (Listmonk's headers + `NewsletterTokenSigner`).

## Newsletter template

`docs/features/newsletter/listmonk-campaign-template.html` follows the same rules (slim header with `email-logo.png`,
`#d63c42`, bold 44 px buttons, `lang`/`<title>` from the subscriber and campaign). Listmonk's Go templates strip HTML
comments, so Outlook conditionals go through `{{ Safe "<!--[if mso]>…" }}`. Upload: see
[`newsletter/README.md`](newsletter/README.md).

## Preview and verify locally

- Mail goes to Mailpit in dev: `http://localhost:8025` (API `/api/v1/search?query=to:x@example.test`,
  `/api/v1/message/{ID}` has `HTML`, `Text` and headers). Only `@example.test` addresses, never production.
- "Your results": `bin/console myspeedpuzzling:send-result-review-email-preview x@example.test --locale=cs --variant=first|weekly|removed`.
- Other e-mails: trigger the flow in the dev app (sign-in link, password reset, …) or send a `TemplatedEmail` with
  sample context from a throwaway script in the `web` container.
- Screenshots: the dev Selenium Chrome (`http://localhost:4444`) opens `http://mailer:8025/view/{ID}.html`; use CDP
  `Emulation.setDeviceMetricsOverride` for real 375 px. `email-logo.png` is only on production after a deploy - swap the
  `src` for a data URI to see it locally.
- Check: the text part (`Text` in Mailpit) holds every URL; 375 px and ~700 px; a long locale (de); a darkened
  background for the logo.
