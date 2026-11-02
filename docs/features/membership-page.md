# Membership page

`/en/membership` (route `membership`, `MembershipController`, `templates/membership.html.twig`). Redesigned
2026-11-03 (mobile first). Draft and the benefit audit behind it: artifact "Membership page redesign".

## The rule: one list of what members get

**`templates/membership/_benefits.html.twig` is THE list of everything a membership unlocks.** Whenever a feature
becomes members-only, gains a members-only part, or stops being members-only, update that list in the same change:

1. Add / change / remove the line in the right group (`understand`, `compete`, `organise`, `yours`), with its link
   (`url`) and, if it is behind a flag, `visible:` (e.g. `insights_visible()`, `xp_system_visible()`).
2. Texts under `membership.benefits.items.<key>.title` / `.text` (+ `.short` when it is a `shortcut` tile) in **all 6
   locales**.
3. Something that stays free for everyone and is worth saying belongs in "Free for everyone, always"
   (`membership.free_forever.items.*`, list in `membership.html.twig`) instead.
4. Never list a feature that is not built ("coming soon" lines were removed on purpose).

The same partial renders the full grouped list on the membership page (`mode: 'groups'`, with "Open" links for
active players) and the shortcut tiles on the free trial's welcome page `free_trial_started` (`mode: 'shortcuts'`,
the lines marked `shortcut: true`). The three-line summary
`templates/membership/_benefit_highlights.html.twig` is shared by the page's hero and the members-only modal
(`#membersExclusiveModal` in `base.html.twig`) - keep it to the three strongest reasons.

## Page structure

| Part | Who sees it |
|---|---|
| Hero: eyebrow, headline, lead, three highlights (`_benefit_highlights`) | everyone (members get a thank-you headline, no highlights) |
| Offer / status card `#membership-offer` (`.card-body`, tests read it) | free trial offer (`_free_trial_offer`) or its unlock date · plan picker (`_subscription_plans`) · trial status · member status with plan switch, voucher periods, discount, payment portal · cancelling · lifetime · expired |
| Three highlight cards with small illustrations | not active |
| Everything included (`_benefits` groups) - folded on phones (first open), open from `lg` via `::details-content`; "Open" links for active players | everyone |
| Free for everyone, always | everyone but paying members |
| Closing call (trial button or "Choose a plan") | not active |
| Questions (`<details>`; referral code input `ReferralCodeInput` inside one) | everyone; trial and referral items only for non-members |
| Sticky bottom bar on phones (`.membership-sticky`, `d-lg-none`) | not active, when there is something to click |

**Plan picker:** two radio cards (monthly first and preselected - Jan prefers the steadier monthly income; yearly keeps its "2 months free" badge, "Works out at …" = `yearlyPerMonthPrice`
from the controller, discounted prices struck through) and one button; `membership_plan_controller.js` points the
button at the picked plan (checkout URL on the web, store product in the apps).

Styles: `assets/styles/_membership.scss` - icons in coral on a soft coral background (one colour, not one per group), benefit groups as cards with a shadow. Texts: `membership.hero|highlights_list|highlights|benefits|shortcuts|
free_forever|closing|plans|faq|sticky|expired_info`. "Where does the money go?" tells Jan and Simona's story - their
words, keep it that way.
