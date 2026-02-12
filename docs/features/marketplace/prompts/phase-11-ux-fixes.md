# Phase 11: UX Fixes & Missing UI Elements

Post-implementation review identified 3 missing UI elements and several UX improvements. Fix all of these.

## Critical Fixes

### 1. "Ships to" Filter Missing from Marketplace UI

The `MarketplaceListing` Live Component has the `shipsTo` property and `getDefaultShipsTo()` pre-fills it with the logged-in user's country — but the filter is NOT rendered in the template.

**Fix**: In `templates/components/MarketplaceListing.html.twig`, add the "Ships to" country select dropdown to the filter bar. It should:
- Be a `<select>` bound to `data-model="shipsTo"`
- Include an empty option "All countries" as default
- List countries from `CountryCode` enum
- Pre-select the logged-in user's country (already handled by `getDefaultShipsTo()`)
- Place it logically near the other filters (after condition or at the end)

### 2. "Report Conversation" Button Missing from Chat UI

The backend is fully implemented (entity, handler, admin dashboard) but there is no button for users to actually report a conversation.

**Fix**: In `templates/messaging/conversation_detail.html.twig`:
- Add a "Report conversation" option to the dropdown menu (next to "Block user")
- Use a small modal or inline form that asks for a reason (textarea, required, max 500 chars)
- Submit via POST to the `report_conversation` route
- Show success flash message after reporting: use translator key like `flashes.conversation_reported`
- Only show on accepted conversations (no point reporting pending/denied)
- Don't show the report button if the conversation is already reported by this user (optional — or just let the handler handle duplicate gracefully)

### 3. Blocked Users Management Page

Users can block other users from the conversation detail page, but there is no way to view blocked users or unblock them.

**Fix**: Create a blocked users page:

**Controller**: `src/Controller/Messaging/BlockedUsersController.php`
- Route: GET `/en/blocked-users` (name: `blocked_users`)
- Requires auth
- Gets blocked users via `GetUserBlocks::forPlayer()`
- Template: `templates/messaging/blocked_users.html.twig`

**Template**: `templates/messaging/blocked_users.html.twig`
- Page title: "Blocked users" (use translator)
- List of blocked users, each showing: avatar, name, country flag, blocked date
- "Unblock" button for each user (POST form to `unblock_user` route)
- Empty state when no blocked users: icon + "You haven't blocked anyone" text
- Link to this page from conversation settings or profile settings

**Navigation**: Add a link to the blocked users page from:
- The conversations list page (small link or in a dropdown/settings area)
- Or the profile edit page

### 4. Add Query Method (if missing)

If `GetUserBlocks::forPlayer()` doesn't return enough data for display (player name, avatar, country), update it to join the player table and return a proper DTO with player display info.

## UX Improvements

### 5. Message Send Loading State

In `templates/messaging/conversation_detail.html.twig`:
- When the message form is submitted, disable the send button and show a spinner or "Sending..." text
- Re-enable after the page reloads or Turbo response completes
- This prevents double-sends and gives visual feedback

Simple approach: add `onclick="this.form.querySelector('button').disabled = true"` or a small Stimulus controller.

### 6. Toast Notifications for Actions

Verify that the following actions show proper flash message toasts (using the existing toast system):
- Blocking a user → success flash
- Unblocking a user → success flash
- Reporting a conversation → success flash
- Accepting a conversation → success flash
- Denying a conversation → success flash
- Sending a message when muted → danger flash with explanation

Check each controller and ensure `$this->addFlash()` is called with translated messages.

### 7. Conversation Detail — Report Form Confirmation

When reporting a conversation, add a `confirm()` dialog before submitting: "Are you sure you want to report this conversation?" (or use a modal).

## Tests

### 8. Write Tests for New Code

**`tests/Controller/Messaging/BlockedUsersControllerTest.php`**:
- Test page loads for authenticated user
- Test shows blocked users
- Test empty state when no blocks
- Test unauthenticated user redirected

**`tests/Controller/Messaging/ReportConversationControllerTest.php`** (if not already tested):
- Test reporting a conversation creates a report
- Test only participant can report

### 9. Run Checks

```bash
docker compose exec web composer run phpstan
docker compose exec web composer run cs-fix
docker compose exec web vendor/bin/phpunit --exclude-group panther
docker compose exec web php bin/console doctrine:schema:validate
docker compose exec web php bin/console cache:warmup
```
