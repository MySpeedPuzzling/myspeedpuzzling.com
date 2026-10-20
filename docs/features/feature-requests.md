# Feature requests: e-mails and "Recently completed"

The feature request board (`/en/feature-requests`, `FeatureRequestList` component). Statuses change through the
internal API / console commands (`UpdateFeatureRequestStatus`); `FeatureRequestStatusChanged` (async) mails people.

## Who gets an e-mail

When a request is **completed or declined**, `NotifyWhenFeatureRequestStatusChanged` sends one e-mail per person,
each with its own wording and small print saying why they get it:

| who                                      | template                                  |
|------------------------------------------|-------------------------------------------|
| the author                               | `feature_request_{completed,declined}`          |
| upvoters (deduplicated, never the author) | `feature_request_{completed,declined}_upvoter`  |
| players following every request, who neither suggested nor upvoted it | `feature_request_{completed,declined}_follower` |

The player's choice is `player.feature_request_emails` (`Value\FeatureRequestEmails`):

- `upvoted` (default for everybody) - the requests they suggested or upvoted, as before this setting existed
- `all` - additionally every other request
- `none` - nothing, **not even their own requests** (an unsubscribe must stop all of them)

Set in the profile settings ("Messaging & Notifications", anchor `#feature-request-emails` - the board links there
with the current choice), on the token e-mail preferences page, or by the e-mail's unsubscribe link.

Every one of these e-mails carries a signed one-click unsubscribe (`List-Unsubscribe` + `List-Unsubscribe-Post`,
RFC 8058, `FeatureRequestEmailsUnsubscribeUrl` → `feature_request_emails_unsubscribe`, same contract as the digest:
GET only shows the page, POST sets `none`) and the e-mail settings link.

## Completion date

`feature_request.completed_at` is set when a request becomes completed, kept when it is marked completed again
(e.g. a GitHub link added later) and cleared for any other status. Requests completed before the column existed have
no date. The list and the detail show "Completed <date>"; the sort **Recently completed** puts the latest completion
first, then the undated completed ones, then the rest.
