# Email Audit & Tracking

## Overview

The email audit system records every email sent by the application, providing a complete audit trail of what was sent, to whom, when, and whether the SMTP server accepted it. It also sets up VERP (Variable Envelope Return Path) for future bounce detection.

## Architecture

### Core Components

1. **`EmailAuditSubscriber`** (`src/EventSubscriber/EmailAuditSubscriber.php`)
   - Listens to Symfony Mailer events: `MessageEvent`, `SentMessageEvent`, `FailedMessageEvent`
   - Thin subscriber — dispatches Messenger messages, holds only `spl_object_id → UUID` correlation in memory
   - Sets VERP return path on the envelope for bounce routing
   - Implements `ResetInterface` for FrankenPHP worker mode safety
   - All methods wrapped in try-catch — audit failures never block email delivery

2. **Messenger Messages & Handlers**
   - `CreateEmailAuditLog` → `CreateEmailAuditLogHandler` — creates `EmailAuditLog` entity, returns UUID (used for VERP)
   - `RecordEmailSendSuccess` → `RecordEmailSendSuccessHandler` — marks audit log as `Sent`, stores SMTP message ID + debug log
   - `RecordEmailSendFailure` → `RecordEmailSendFailureHandler` — marks audit log as `Failed`, stores error message + debug log
   - `CleanupEmailAuditLogs` → `CleanupEmailAuditLogsHandler` — deletes entries older than specified retention period

3. **`EmailAuditLog`** (`src/Entity/EmailAuditLog.php`)
   - Records: recipient, subject, timestamp, transport name, email type, SMTP message ID, debug log
   - Status lifecycle: `Pending` → `Sent` or `Failed`
   - Supports bounce tracking fields (for future use): bounceType, bouncedAt, bounceReason

4. **Admin UI** — Live Component at `/admin/email-audit`
   - Filterable list with pagination (recipient search, status filter, email type filter)
   - Detail view showing full SMTP debug log

### Event Flow

```
Email Handler → Mailer::send() → Messenger Queue → Worker → AbstractTransport::send()
                                                              ↓
                                                         MessageEvent (pre-send)
                                                         → Subscriber dispatches CreateEmailAuditLog
                                                         → Handler creates entity (status: Pending)
                                                         → Subscriber sets VERP return path
                                                              ↓
                                                         doSend() (SMTP transaction)
                                                              ↓
                                              ┌─── Success ──────────┐  ┌─── Failure ──────────┐
                                              │ SentMessageEvent      │  │ FailedMessageEvent    │
                                              │ → RecordEmailSend     │  │ → RecordEmailSend     │
                                              │   Success dispatched  │  │   Failure dispatched  │
                                              │ → Status: Sent        │  │ → Status: Failed      │
                                              └───────────────────────┘  └───────────────────────┘
```

## What Is Tracked

| Data | Source | Always Available |
|------|--------|-----------------|
| Recipient email | Email `To` header | Yes |
| Subject | Email subject | Yes |
| Sent timestamp | ClockInterface at send time | Yes |
| Email type | Template name (e.g., `competition_approved`) | Yes (for TemplatedEmail) |
| Transport name | SMTP DSN string | Yes |
| SMTP Message ID | Server response after DATA | On success only |
| SMTP Debug Log | Full SMTP conversation | On success and some failures |
| Error message | Exception message | On failure only |

## What Is NOT Tracked

| Data | Why |
|------|-----|
| Actual inbox delivery | SMTP `250 OK` only means "accepted into MTA queue" |
| Bounce (without VERP processing) | Requires receiving and parsing bounce emails |
| Email opened | Would require tracking pixel (hurts deliverability) |
| Link clicked | Would require URL rewriting (hurts deliverability) |
| Spam folder placement | Invisible to sender |

## VERP Setup

### What VERP Does

Each outgoing email gets a unique return path: `bounce+{auditLogId}@{BOUNCE_EMAIL_DOMAIN}`. When a destination server rejects the email after initial acceptance, it sends a bounce message to this unique address. By parsing the address, we can match the bounce to the original email in the audit log.

### Configuration

Set `BOUNCE_EMAIL_DOMAIN` in your environment:

```
BOUNCE_EMAIL_DOMAIN=mail.myspeedpuzzling.com
```

Leave empty to disable VERP (audit logging still works without it).

### Mail Server Setup (Manual)

1. Configure a catch-all or wildcard mailbox for `bounce+*@mail.myspeedpuzzling.com` on your mail provider
2. Verify the mail provider allows arbitrary MAIL FROM addresses within the configured domain
3. Set up IMAP access credentials for the bounce mailbox

### Future: Bounce Processing

A `ProcessBounceEmailsCommand` will:
1. Connect to the bounce mailbox via IMAP
2. Read unprocessed bounce messages
3. Parse DSN (RFC 3464) to determine bounce type (hard/soft)
4. Extract audit log ID from the VERP address
5. Update the `EmailAuditLog` entry with bounce information
6. Run via cron every 5-10 minutes

## Cleanup

Old audit log entries are cleaned up by:

```bash
docker compose exec web php bin/console myspeedpuzzling:cleanup-email-audit-logs 90
```

This deletes entries older than 90 days (configurable via argument). Run weekly via cron.

## Deliverability Considerations

### Why No Open/Click Tracking

This system deliberately does NOT add tracking pixels or URL rewriting to emails:

- **Tracking pixels** can trigger spam filters and are increasingly blocked by email clients (Apple Mail Privacy Protection, ProtonMail)
- **URL rewriting** (click tracking) creates URL mismatches that spam filters flag as phishing patterns (+1 to +3 SpamAssassin points)
- For transactional emails, deliverability is more important than engagement metrics

### Future Options for Delivery Tracking

If delivery tracking becomes critical:

1. **Postal** (self-hosted) — Replace SMTP provider with a self-hosted mail server that provides delivery webhooks. Zero spam score impact since tracking happens at MTA level.
2. **Provider with Symfony Webhook support** — Switch to Postmark, Resend, or similar. Symfony 6.3+ has standardized webhook parsers for delivery events.
3. **Bounce processing** (current VERP setup) — Detect delivery failures by processing bounce messages. No spam score impact.
