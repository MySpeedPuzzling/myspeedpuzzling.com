# Comprehensive Code Review — Marketplace Feature

You have just implemented the full marketplace feature across Phases 2–10. Now perform a thorough code review covering architecture, business logic, security, test coverage, and translations.

Read the feature specifications in `docs/features/marketplace/` (files `01-marketplace.md` through `09-puzzle-detail-integration.md` and `10-implementation-plan.md`) to understand the expected behavior, then review ALL new and modified code.

## How to Review

Go through each area below systematically. For each issue found:
1. State the file and line
2. Describe the issue
3. Fix it immediately

Do NOT just list issues — fix them as you go.

---

## 1. Architecture Review

### 1.1 CQRS Pattern Compliance

Check every new Message (command) and Handler:

- [ ] All messages are `readonly final class` with public constructor properties
- [ ] All handlers use `#[AsMessageHandler]` attribute
- [ ] All handlers are `readonly final class` with `__invoke()` method
- [ ] Handlers inject repositories/services via constructor, not service locator
- [ ] No business logic in controllers — controllers only dispatch messages and render templates
- [ ] Domain exceptions are thrown from handlers, caught appropriately in controllers (or allowed to bubble with `#[WithHttpStatus]`)

### 1.2 Entity Design

Check every new entity:

- [ ] Primary keys use `UuidInterface` with `UuidType::NAME`
- [ ] IDs created with `Uuid::uuid7()` (not v4)
- [ ] Immutable properties use `#[Immutable]` attribute
- [ ] Mutable properties use `#[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]` with explicit setter methods
- [ ] Proper Doctrine mapping attributes (`#[Column]`, `#[ManyToOne]`, `#[JoinColumn]`)
- [ ] Appropriate indexes on foreign keys and frequently queried columns
- [ ] UniqueConstraints where needed (e.g., one rating per reviewer per transaction, one block per pair)

### 1.3 Controller Patterns

Check every new controller:

- [ ] Single-action controllers with `__invoke()` method only
- [ ] Extends `AbstractController`
- [ ] Class is `final`
- [ ] Route attributes with at least English path (multi-language paths optional but consistent with existing)
- [ ] `#[IsGranted('IS_AUTHENTICATED_FULLY')]` where authentication is required
- [ ] `#[CurrentUser] User $user` for getting the current user
- [ ] Constructor uses `readonly private` for injected services
- [ ] No direct entity manipulation — dispatches messages via `MessageBusInterface`

### 1.4 Query Patterns

Check every new Query class:

- [ ] Query classes are `readonly` services injected with `Connection` (DBAL)
- [ ] Use raw SQL for read operations (not DQL/QueryBuilder)
- [ ] Return typed Result DTOs, not raw arrays
- [ ] Result DTOs are `readonly final class` with public constructor properties
- [ ] DTOs hydrated via `fromDatabaseRow()` static method or in query class
- [ ] No write operations in query classes

### 1.5 FrankenPHP Worker Mode Safety

- [ ] Any service that caches data in instance properties implements `ResetInterface`
- [ ] `MercureNotifier` (or equivalent) implements `ResetInterface`
- [ ] No static mutable state in new services
- [ ] Test doubles (NullMercureHub) implement `ResetInterface`

### 1.6 Value Objects & Enums

- [ ] All new enums are in `SpeedPuzzling\Web\Value` namespace
- [ ] Enums use `string` backing type with lowercase values
- [ ] Value objects are `readonly final class`

---

## 2. Security Review — CRITICAL

This is the most important section. Every endpoint must be checked for authorization.

### 2.1 Conversation Access Control

For every controller and handler that accesses conversations or messages, verify:

- [ ] **ConversationDetailController**: Verifies the logged-in user is either `initiator` or `recipient` of the conversation. A user must NEVER be able to view another user's conversations. Write a test: create a conversation between Player A and Player B, then try to access it as Player C — must return 403/404.
- [ ] **SendMessageController**: Verifies sender is a participant in the conversation
- [ ] **AcceptConversationController**: Verifies only the `recipient` can accept (not the initiator, not a third party)
- [ ] **DenyConversationController**: Verifies only the `recipient` can deny
- [ ] **MarkMessagesAsRead**: Only marks messages for the requesting player, never for the other participant

### 2.2 Listing Ownership

- [ ] **MarkAsReservedController**: Only the listing owner can mark as reserved
- [ ] **RemoveReservationController**: Only the listing owner can remove reservation
- [ ] **EditSellSwapListItemController** (existing): Ownership check still works

### 2.3 Rating Authorization

- [ ] **RateTransactionController**: Only the seller or buyer of that specific transaction can rate
- [ ] A player cannot rate a transaction they are not part of — test this!
- [ ] A player cannot rate twice for the same transaction — test this!

### 2.4 Block System

- [ ] A blocked user cannot start a conversation with the blocker
- [ ] A blocked user cannot send messages to the blocker
- [ ] Blocking is one-directional: if A blocks B, B cannot message A, but A can still message B (unless B also blocks A)

### 2.5 Admin-Only Routes

- [ ] All `/admin/moderation/*` routes check admin status
- [ ] Non-admin users get 403 when trying to access admin routes — test this!
- [ ] Admin conversation log access is restricted to admin only (regular users cannot view other people's conversations via this route)

### 2.6 Query-Level Security

Check all query classes for data leakage:

- [ ] `GetConversations::forPlayer()` — SQL WHERE clause filters by the given playerId as participant. Not possible to pass another player's ID and get their conversations through the query directly (handlers/controllers must ensure the playerId comes from the authenticated user, not from URL params)
- [ ] `GetMessages::forConversation()` — the controller calling this must first verify the user is a participant
- [ ] `GetConversationLog::fullLog()` — only called from admin controllers

### 2.7 Write Security Tests

If any of the following tests don't exist yet, create them:

```
tests/Security/ConversationAccessTest.php
```
- Test: User cannot view conversation they are not part of (expect 403 or 404)
- Test: User cannot accept/deny conversation they are not recipient of
- Test: User cannot send message in conversation they are not part of
- Test: Non-admin cannot access moderation dashboard (expect 403)
- Test: Non-admin cannot access conversation log via admin route
- Test: Blocked user cannot start conversation
- Test: User cannot rate transaction they are not part of
- Test: Only listing owner can mark as reserved

---

## 3. Business Logic Review

### 3.1 First-Contact Approval Flow

- [ ] New conversation starts with status `pending`
- [ ] Messages in pending conversations are NOT visible to the recipient until accepted
- [ ] After acceptance, all messages become visible
- [ ] Denied conversations cannot receive new messages
- [ ] Auto-accept works: if two users already have an accepted conversation, new marketplace conversations between them are auto-accepted

### 3.2 Contactability Setting

- [ ] When `allowDirectMessages = false`, general conversations cannot be started to that user
- [ ] Marketplace conversations ALWAYS work regardless of this setting (sellers must be contactable for their listings)
- [ ] If User A has been contacted by User B first and has an existing conversation, User A can always reply regardless of setting

### 3.3 Reserved Status

- [ ] Reserved items remain visible in marketplace (not hidden)
- [ ] Reserved badge displays correctly
- [ ] Only owner can reserve/unreserve
- [ ] Marking as sold still works on reserved items

### 3.4 Ratings

- [ ] Ratings only possible when `buyerPlayer` is not null (both registered)
- [ ] 30-day window enforced
- [ ] Denormalized `averageRating` and `ratingCount` on Player entity update correctly
- [ ] Average calculation is correct (not off-by-one, handles first rating correctly)

### 3.5 Email Notifications

- [ ] 12-hour delay respected
- [ ] No duplicate emails for same unread batch
- [ ] Timer resets when user reads or responds
- [ ] Players without email are skipped
- [ ] Opt-out setting respected
- [ ] Email groups messages per sender

### 3.6 Moderation Enforcement

- [ ] Muted users cannot send messages (handler throws exception)
- [ ] Muted users cannot start conversations (handler throws exception)
- [ ] Marketplace-banned users cannot create listings (handler throws exception)
- [ ] Auto-unmute works when `messagingMutedUntil` expires
- [ ] UI shows clear message when user is muted/banned

### 3.7 Shipping Filter

- [ ] Ships-to-country filter works with JSON containment query
- [ ] Filter pre-fills with logged-in user's country
- [ ] Sellers without shipping countries configured are NOT excluded when filter is empty

---

## 4. Test Coverage Review

### 4.1 Check Test Existence

Verify that tests exist for every new handler, query, and controller. Run:

```bash
docker compose exec web vendor/bin/phpunit --exclude-group panther --list-tests 2>&1 | grep -i -E "marketplace|conversation|message|rating|moderation|reserved|shipping|block|unread"
```

### 4.2 Required Test Categories

For each phase, ensure these test types exist:

**Handlers (unit/integration):**
- [ ] Happy path (normal operation succeeds)
- [ ] Authorization (wrong user gets exception)
- [ ] Edge cases (duplicate operations, expired windows, etc.)
- [ ] Domain rule enforcement (blocked users, muted users, disabled settings)

**Queries (integration):**
- [ ] Returns correct data
- [ ] Filters work correctly
- [ ] Empty results handled
- [ ] Pagination works

**Controllers (functional):**
- [ ] Authenticated access works (200)
- [ ] Unauthenticated access redirects or returns 401/403
- [ ] POST operations redirect correctly
- [ ] Wrong user gets 403/404

### 4.3 Missing Tests

If you find handlers, queries, or controllers without corresponding tests, create them. Prioritize:
1. Security tests (authorization failures)
2. Handler edge cases
3. Query correctness

### 4.4 Fixture Completeness

- [ ] `ConversationFixture` has conversations in all states (pending, accepted, denied)
- [ ] `ChatMessageFixture` has read and unread messages
- [ ] `UserBlockFixture` has at least one block
- [ ] `TransactionRatingFixture` has ratings from both seller and buyer perspectives
- [ ] `ConversationReportFixture` has pending and resolved reports
- [ ] `SellSwapListItemFixture` has reserved items
- [ ] Fixtures reference correct player constants for testing authorization

### 4.5 Run Full Test Suite

```bash
docker compose exec web vendor/bin/phpunit --exclude-group panther
```

Every test must pass. Fix any failures.

---

## 5. Translation Review — No Hardcoded Strings

### 5.1 Template Scan

Scan ALL new templates for hardcoded user-facing strings. Every text visible to users must use the Symfony translator:

```bash
# Find templates with potential hardcoded strings
# Look in all new template directories
```

Check these directories:
- `templates/marketplace/`
- `templates/messaging/`
- `templates/rating/`
- `templates/sell-swap/` (modified templates)

**Rules:**
- ALL user-facing text must use `{{ 'translation.key'|trans }}` or `{% trans %}` blocks
- Button labels, headings, badges, empty states, flash messages, form labels, placeholders — ALL must be translated
- **Exception**: Admin templates (`templates/admin/moderation/`) can have hardcoded English strings
- Translation keys should be in English and descriptive, e.g., `marketplace.filter.search_placeholder`, `messaging.request.accept_button`

### 5.2 Controller Flash Messages

Check all controllers for flash messages — they must use the translator:

```php
// WRONG:
$this->addFlash('success', 'Message sent successfully');

// CORRECT:
$this->addFlash('success', $this->translator->trans('flashes.message_sent'));
```

### 5.3 Form Labels and Placeholders

Check all new FormTypes — labels, placeholders, help text, and choice labels must use translation keys:

```php
// WRONG:
->add('stars', ChoiceType::class, ['label' => 'Rating'])

// CORRECT:
->add('stars', ChoiceType::class, ['label' => 'rating.form.stars'])
```

### 5.4 Exception Messages (User-Facing)

If any exception messages are shown to users (via flash messages or error pages), they must be translated. Internal exception messages (for logs/developers) can stay in English.

### 5.5 Email Templates

Check `templates/emails/unread_messages.html.twig`:
- Subject line must be translated (use player's locale)
- Email body must use translator with player's locale
- Follow existing email template patterns (check existing emails like `membership_subscribed.html.twig` for reference)

### 5.6 JavaScript/Stimulus

Check Stimulus controllers for any user-facing strings:
- Toast messages dispatched from JS should use data attributes with translated values from the server
- No hardcoded strings in JS that are shown to users

### 5.7 Translation File

Verify that all translation keys used in templates actually exist in the translation file(s). Check the existing translation structure:

```bash
# Find translation files
find src/ config/ translations/ -name "*.en.*" -o -name "messages.*" 2>/dev/null
```

Ensure all new keys are added to the English translation file.

---

## 6. Final Checks

### 6.1 Static Analysis

```bash
docker compose exec web composer run phpstan
```

Fix ALL issues. PHPStan must pass at max level.

### 6.2 Coding Standards

```bash
docker compose exec web composer run cs-fix
```

### 6.3 Schema Validation

```bash
docker compose exec web php bin/console doctrine:schema:validate
```

### 6.4 Cache Warmup

```bash
docker compose exec web php bin/console cache:warmup
```

### 6.5 Test Suite

```bash
docker compose exec web vendor/bin/phpunit --exclude-group panther
```

ALL tests must pass.

### 6.6 Review Summary

After completing the review, provide a summary:
1. Total issues found and fixed (categorized by severity: critical/medium/minor)
2. New tests added
3. Translation keys added
4. Any remaining concerns or recommendations
