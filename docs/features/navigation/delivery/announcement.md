# Navigation redesign — announcement drafts (LAUNCH-04)

Drafts for the launch post and the weekly "You said, we changed" notes. **Not published anywhere.** English only: the owner translates at publish time into cs, de, es, fr and ja (with the native-speaker check of the tab and Menu words, README §11). The post describes what the delivery branch actually built (`delivery/state.md`, Decisions log), not only what the [README](../README.md) planned; where the two differ, this file follows the build.

Rules for every text here: plain and friendly; never blame the player ("was hard to find", never "you couldn't find"); no player names, no numbers about people, no business numbers; never "rival", never "suspicious". Pictures: the "What moved" schematics or screenshots of a demo account, never a real player's page.

**Ship check.** Lines marked *(ship check: ID)* describe items that were not verified when this draft was written (2026-10-14). Before publishing, look the ID up in `state.md`: keep the line when the item is `verified` and switched on in production, otherwise delete it. §5 lists them.

---

## 1. The post

### Headline

**Finding your way around MySpeedPuzzling got easier: five places at the bottom, everything else in ☰ Menu**

Shorter alternatives: "A new way around MySpeedPuzzling" · "Everything is in ☰ Menu, where the ☰ always was".

### Why

On phones, almost everything on MySpeedPuzzling sat behind one menu button, and many of you told us that pages were hard to find. Now the places you use most are always on screen with a name under every icon, and everything else lives in one complete Menu, in the same corner as before. Along the way your profile shows your puzzling first, the Inbox shows what is really meant for you, and taps and the Back button behave the way your phone does.

### What changed

**Five places at the bottom of your phone.** Home · Puzzles · Add · Community · Profile are always on screen, each with its name under the icon. Add is the coral button in the middle. Tap the place you are already in to go back to its start, or to the top of the page; at the top of Home it refreshes the feed. If a name does not fit on a small screen, the names get a little smaller, and if that is still not enough only the current place keeps its name — press and hold a tab to see its name. Visitors who are not signed in see the same five places.

**One slim bar at the top.** The logo (back to Home) · search · **?** Help & feedback · Inbox · **☰ Menu**. The ☰ stays where it always was, at the right edge. The bar slides away while you scroll down and comes back as soon as you scroll up. The search field finds puzzles, puzzlers **and pages & settings**: try "export", "password" or an old name such as "Puzzle database" — page results tell you where to find them in the Menu. The barcode scanner sits inside the field (on the smallest phones, inside the opened search). While a stopwatch is running, a small timer shows in the bar on every page; tap it to go back to your stopwatch.

**☰ Menu: every page in one place.** The Menu opens over the page you are on; Back or × closes it, and the five places at the bottom stay usable. It is grouped the same way for everyone and never reshuffles: You (profile, settings, membership) · Inbox · Your roles · Your puzzling · Your puzzles · Community · Puzzles · Add & track · Account · Help & learn · About · Log out. It has its own search at the top, which lists pages and settings first. Organisers find "Events you organize" under Your roles; moderators find their review queues there too, with the number of waiting items (a small dot on ☰ tells them something is waiting).

**On a computer.** A slim top bar: ☰ · logo · search (Ctrl/Cmd + K jumps into it) · your running stopwatch · **+ Add** · Feedback · Inbox · your avatar. The avatar opens a short account menu: View profile · Settings · Membership · Language · Log out. On wide screens the Menu stays open as a column on the left; on medium screens a slim rail shows Home · Puzzles · Community · Profile instead. ☰ switches between the two, and that computer remembers your choice. On smaller screens and tablets, ☰ opens the Menu over the page.

**Inbox: notifications and messages together.** The bell and the messages icon became one **Inbox** with two tabs, **For you** and **Messages**. The one number on the Inbox counts what is new for you, and the Inbox opens on the tab that holds it. "A favorite added a time" no longer goes to the Inbox: your favorites' new times are on **Home › Favorites**, where everything new since your last visit is marked. If you follow other puzzlers, for the first month a short note in the Inbox points there.

**Home.** For signed-in players Home is your feed — **Favorites** first when you follow someone, then **Everyone**. The logo and the Home tab both lead there. Visitors who are not signed in keep the homepage.

**Puzzles.** Four sections: **My library** · **All puzzles** · **Marketplace** · **What next?**. The Puzzles tab reopens the section you used last. My library holds your collections, wishlist, unsolved puzzles, sell/swap list and lent & borrowed puzzles.

**Add: one page, three ways.** **Speed Puzzling**, **Relax** (no time) or **Collection** — the page opens in the way you used last. Under Speed Puzzling you find **Use the stopwatch**, under Collection **Scan several boxes**. On a puzzle page, Add starts with that puzzle. While the stopwatch runs, the bars step aside so the timer has the whole screen; a button takes you back out. If you are not signed in, Add explains what you can track and how to start.

**Community.** A new Community page with three doors: **Leaderboards** · **Puzzlers** · **Events**, each with a small preview of what is on the other side. Every Community page has the three as chips at the top, so you can switch with one tap. Compare lives under Puzzlers.

**Your profile, puzzling first.** The Profile tab opens your own profile page — the same page others see — with **Edit profile** and **Share** at the top. The tabs are Overview · Results · Statistics · Calendar · Achievements · Library · Favorites (Favorites only you see).
- **Overview**: your best times per piece count, your latest results, your achievements and the "about you" part.
- **Results** is a new page with all your results: search them by puzzle name, EAN or brand code; view them by puzzle or every single result; newest first by default, with every sort and filter you had before; your rank on each solo result; 50 at a time with "Show more". The filters stay in the address, so you can share a filtered list, and Back from a puzzle brings you to the row you came from.
- **Statistics** now also holds the average-time chart and the skill and rating cards (what was for members stays for members).

**Player card.** Tap a player's name or picture and a small card opens — from the bottom on phones, next to the name on a computer: their profile link, code, country, level, latest puzzles, links to their Results and Statistics, and Favorite · Compare · Message · Share. The card fits the page: on a puzzle page it shows how they did on that puzzle next to you, in a feed that result, on a leaderboard their place and yours. Ctrl/Cmd + click or a middle click still opens the profile right away. Blocking stays on the profile page. Players without membership now see a small "Members" lock on the skill tier instead of a blurred strip.

**Help & feedback, one tap away.** **?** on phones and **Feedback** on a computer open a sheet with a message box at the top — the page you are on is attached for you. On a puzzle page, "Suggest a correction to this puzzle" comes first. Below: Ideas & feature requests · FAQ · Getting started · How progress works · Where did it go? · Language (and "Reload page" in the installed app). Every page also ends with **"Something wrong on this page? Tell us"**. Swipe the sheet down to close it; what you typed waits for you. Visitors who are not signed in get the help links and a "Sign in to send feedback" button. We also fixed the phone screen that stayed grey after sending feedback.

**Back, gestures and the feel of it.**
- Back — the button or the swipe — closes whatever you opened (the Menu, a card, a sheet, the search) and leaves you on your page.
- Swipe a sheet down to close it.
- Every tap shows at once.
- Pinch-to-zoom works on every page.
- "Are you sure?" questions are now our own dialogs, so they also show on iPhones after you went Back.
- In the installed app, pull-to-refresh needs a shorter pull.
- On Android: a ripple under your finger, and a short vibration when a time is saved, a box is scanned or something fails — you can turn it off in Settings › Preferences › Vibration *(ship check: GEST-10, GEST-11)*. On iPhone, switches feel like iOS's own.
- Pressing and holding a tab whose name is hidden shows the name *(ship check: GEST-18)*.
- Going Back now brings you to the same place instantly, without loading the page again *(ship check: GEST-14, GEST-15 — on iPhones only when `INSTANT_BACK_IOS` is on)*.
- On Android, places fade into each other *(ship check: GEST-12)*.
- On phones, swipe sideways on Home between Favorites and Everyone *(ship check: GEST-16)*.
- On phones, a puzzle's picture grows into its page when you open it from a list *(ship check: GEST-17)*.
- The pull-to-refresh spinner in the installed app looks like your phone's own *(ship check: GEST-19)*.

**Footer and "All pages".** The footer looks the same on every page, in four columns: Puzzles · Community · Learn · MySpeedPuzzling. Every link it had before is still there; new pages carry a "New" label for two weeks. "All pages" at the bottom opens the Menu.

**Pages that need the whole screen.** A running stopwatch, the organisers' participants spreadsheet, the round stopwatch, live results entry and print pages show no bars. Sign-in pages show only the logo and the language switch.

**Where did it go?** A permanent page lists every item of the old menus, top bar and footer and where it is now — for example "☰ Menu › Account › Your data" — each one a working link, with a filter box. Signed-in players reach it from the Help sheet, the Menu, the search and the footer; the page itself is open to everyone. If you had an account before the change, a short "What moved" note explains it once, the first time you visit after it, starting with "Everything is in ☰ Menu, top right, where the ☰ always was" (on a computer: "Everything is in the ☰ Menu on the left"). For the first month, a line on Home and at the top of the Menu points there too *(ship check: LAUNCH-01, LAUNCH-02, LAUNCH-03)*.

### How to give feedback

Tell us what works and what does not — especially anything you cannot find:
- **?** (phones) or **Feedback** (computer) on every page — the page is attached for you.
- **"Something wrong on this page? Tell us"** at the end of every page.
- **☰ Menu › Help & learn › Send feedback**.
- Bigger wishes: **Ideas & feature requests**, where others can vote for them.

We read everything. Every week we post a short "You said, we changed" note with what we fixed or moved because of your messages. Looking for something specific? **Where did it go?** (in ☰ Menu › Help & learn) shows where every old menu item lives now.

### Short version (social posts, newsletter teaser)

> **A new way around MySpeedPuzzling.** Five places are now always at the bottom of your phone — Home, Puzzles, Add, Community, Profile — and everything else is in ☰ Menu: on your phone top right, where the ☰ always was; on a computer it is on the left. The search finds pages and settings too, and "Where did it go?" shows where every old menu item lives now. Tell us what you think: tap **?** on any page.

---

## 2. "You said, we changed" — template

Weekly for at least four weeks after the launch, then whenever something changes because of feedback. One line per change, newest first; the same text in every channel the post went to (§3, rows 4–5 and 11).

```
You said, we changed · <DATE>

· <what we heard - the player's point in plain words, no name> → <what changed - one sentence> · <link: the page, or the Menu path such as ☰ Menu › Account › Your data>
· <what we heard> → <what changed> · <link>

Kept as it is: <what we heard> → <why we kept it - one sentence>   (optional, only when many asked)
Thank you for every message - keep them coming: tap ? on any page.
```

As a table, for a page or a newsletter block:

| Date | What we heard | What changed | Link |
|---|---|---|---|
| `<DATE>` | `<what we heard>` | `<what changed>` | `<link or ☰ Menu path>` |

Writing rules: what we heard is a summary, never a quote that could identify the sender (a name only with their permission); never a number about a person; when something stays as it is, say why in one neutral sentence; link the page or the exact Menu path so the reader can try it at once; the owner translates each note at publish time like the post.

Made-up example (not a real message): `<DATE>` · "Where is my data export now?" → the search now also finds "download my results", and the Menu row says what it holds · ☰ Menu › Account › Your data.

---

## 3. Channel checklist

| # | Channel | What goes there | When / who | Done |
|---|---|---|---|---|
| 1 | Site: "What moved" modal | automatic, once per player, accounts created before `NavigationLaunch::DATE` (LAUNCH-01) | owner sets `DATE` at release; check it shows on D | ☐ |
| 2 | Site: pointers | automatic for 30 days: Home banner + top line of the Menu (LAUNCH-03), the Inbox note about Home › Favorites (INB-05) | check on D, signed in with an old account | ☐ |
| 3 | Site: Where did it go? | permanent page (LAUNCH-02); open it once in every locale and try three old items | D | ☐ |
| 4 | Newsletter (Listmonk, the 6 locale lists) | the full post (§1), translated; campaign template `docs/features/newsletter/listmonk-campaign-template.html`; pictures = schematic or demo account | owner, D … D+2 | ☐ |
| 5 | Instagram | the short version + one picture of the bars and the ☰; link to the site | owner, D | ☐ |
| 6 | Other community spaces the owner posts in | the short version + link | owner | ☐ |
| 7 | Organisers | short personal note: "Events you organize" is in ☰ Menu › Your roles (and "You organize" on Events); their organiser pages work as before; "Tell us" line on every page | owner, D | ☐ |
| 8 | Moderators and admins | the review queues and admin pages are in ☰ Menu › Your roles with their counts; the dot on ☰ shows when something waits | owner, D | ☐ |
| 9 | Ideas & feature requests | mark the requests this release answers as completed (`feature-request-complete`; send the `adminComment` again with the completion) with a one-line "what changed" | owner, D … D+7 | ☐ |
| 10 | Replies to earlier feedback | players whose navigation feedback this release answers (the feedback mails carry a reply-to) | owner, D … D+7 | ☐ |
| 11 | Weekly "You said, we changed" | §2, in channels 4–6 | weekly from D+7, at least 4 weeks | ☐ |
| 12 | GitHub | the merge's PR description links the README and this file | at merge | ☐ |
| 13 | Translation | the post, the short version and every weekly note in cs, de, es, fr, ja; native-speaker check of the tab and Menu words and of the French search word "Chercher" | owner, before 4–6 | ☐ |

---

## 4. Surface checklist (Verify: every changed surface covered)

Every README §4 surface, plus the other changes the implementation plan lists for this post, mapped to its paragraph in §1.

| README | Surface | Paragraph in §1 | Notes |
|---|---|---|---|
| §4.1 | Phone top bar (logo, search + scan, ?, Inbox, ☰, hide on scroll, stopwatch pill, installed-app back button) | "One slim bar at the top" | back button not mentioned: it only exists in the installed app and needs no explanation |
| §4.2 | Phone bottom bar (five places, labels, label fallback) | "Five places at the bottom of your phone" | |
| §4.3 | Desktop top bar, account menu, docked column, rail, overlay, 768–1023 compact bar | "On a computer" | compact bar = "smaller screens and tablets" |
| §4.4 | Inbox (For you / Messages, one badge, opens on the right tab, favorites' times moved) | "Inbox: notifications and messages together" | + D4 (favorites' times on Home) |
| §4.5 | Help & feedback sheet, end-of-page line, guests, grey-screen fix | "Help & feedback, one tap away" | |
| §4.6 | Add page (modes, last mode, stopwatch, multiscan, guest explainer, stopwatch page without bars) | "Add: one page, three ways" | pill: "One slim bar at the top" |
| §4.7 | Profile content first, player tabs, Overview, Results, Statistics | "Your profile, puzzling first" | |
| §4.8 | Player card (contexts, actions, own card, Block on the profile) | "Player card" | + §6 teaser (locked tier chip, no blurred strip) |
| §4.9 | Community landing + section chips | "Community" | |
| §4.10 | Pages without bars, auth layout, keyboard | "Pages that need the whole screen" | keyboard hiding the bar not mentioned (expected behaviour) |
| §4.11 | Footer + "All pages" + "New" labels | "Footer and 'All pages'" | |
| §4.12 | The Menu (groups, roles, counts, ☰ dot, Menu search, pages & settings search) | "☰ Menu: every page in one place" + "One slim bar at the top" (search) | |
| §4.13 | Gestures and platform feel (G1–G17) | "Back, gestures and the feel of it" | G9, G12, G14, G15, G17 lines carry ship checks |
| §4.14 | Native-ready navigation | — **deliberately not announced** | no visible change on the web; mentioning it would promise an app that is out of scope (README §9) |
| D5 | Home = Hub for signed-in players | "Home" | |
| D6 | Puzzles sections (no §4 subsection) | "Puzzles" | |
| §8 | "What moved" modal, pointers, Where did it go? | "Where did it go?" | |

---

## 5. Ship checks before publishing

State on 2026-10-14 (`state.md`); re-check each on publish day.

| ID | Line in §1 | State then | If not shipped |
|---|---|---|---|
| GEST-10, GEST-11 | Android ripple and vibration, Settings › Preferences › Vibration | implemented, verify-after the Platform feel gate | delete the Android line |
| GEST-18 | press and hold a tab to see its name (also in "Five places") | implemented, verify-after the Platform feel gate | delete both mentions |
| GEST-14, GEST-15 | Back brings you to the same place instantly | todo | delete the line; on iPhones keep it only when `INSTANT_BACK_IOS` is true |
| GEST-12 | Android places fade into each other | in progress | delete the line |
| GEST-16 | swipe between Favorites and Everyone | in progress | delete the line |
| GEST-17 | the puzzle picture grows into its page | in progress | delete the line |
| GEST-19 | installed-app pull-to-refresh spinner | in progress | delete the line |
| LAUNCH-01, LAUNCH-02, LAUNCH-03 | "What moved" note, Where did it go? content, pointers | in progress | rewrite the paragraph to what exists; channels 1–3 in §3 |
| owner | `NavigationLaunch::DATE` set | `null` | without it there is no modal and no pointers: hold the post |

Also before publishing: the newest Decisions log rows of `state.md` (a later decision can move a page or rename a label), the six-locale screenshots of QA-01 for the picture, and the names in any picture (demo account only).
