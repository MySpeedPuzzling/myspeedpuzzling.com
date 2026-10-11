# Gamification and progression research for MySpeedPuzzling

Research date: 2026-10-10. Web research, no code changes.

**Evidence labels used throughout**

- **[P]** Peer-reviewed study or randomised field experiment.
- **[C]** Published by the company itself (engineering blog, help centre, official announcement).
- **[S]** Secondhand: a company talk or number reported by a third party. It is plausible, but the original is not available.
- **[O]** Opinion, practitioner blog, forum or anecdote.

Where a number could not be traced to a primary source, the text says so.

---

## A. What works for retention and conversion, and what backfired

### A1. The research base on gamification

- **Gamification works, but the effect is small and depends on context.**
  - **Sailer & Homner (2020)** is a meta-analysis of gamification in learning [P]. The effect sizes were small:
    - cognitive outcomes g = .49
    - motivational outcomes g = .36
    - behavioural outcomes g = .25 (the least stable)

    Effects on motivation and behaviour were less robust in the more rigorous studies.
    [Springer, open access](https://link.springer.com/article/10.1007/s10648-019-09498-w)
  - **Hamari, Koivisto & Sarsa (2014)** is a literature review [P]. It found "positive effects, however, the effects are greatly dependent on the context … as well as on the users". It flags novelty effects as a recurring caveat.
    [Aalto](https://research.aalto.fi/fi/publications/does-gamification-work-a-literature-review-of-empirical-studies-o/)
- **Points, levels and leaderboards increase quantity, not intrinsic motivation.**
  - **Mekler et al. (2017)** ran an image-tagging experiment [P]. Points, and especially levels and leaderboards, produced significantly more tags than the control. They did not change intrinsic motivation or competence. The authors treat these elements as extrinsic incentives for *quantity*.
    [unibas](https://edoc.unibas.ch/32047/)
- **The overjustification effect is real for expected, tangible rewards, and reversed for informational feedback.**
  - **Deci, Koestner & Ryan (1999)** is a meta-analysis of 128 studies [P].
    - Expected rewards undermined free-choice intrinsic motivation:
      - engagement-contingent: d = −0.40
      - completion-contingent: d = −0.36
      - performance-contingent: d = −0.28
    - Positive feedback *enhanced* motivation:
      - free choice: d = +0.33
      - self-reported interest: d = +0.31

    Lesson: a reward that *informs* ("you did this well") helps. A reward that *controls* ("do this to get that") can crowd out the hobby.
    [PDF](https://depts.washington.edu/techdocs/papers/deciExtrinsicRewardsAndIntrinsicMotivation99.pdf)
- **The most cited cautionary result is a gamified classroom.**
  - **Hanus & Fox (2015)** gave one course mandatory badges and a leaderboard [P]. Over 16 weeks those students showed *less* motivation, satisfaction and empowerment, and lower exam scores (mediated by motivation). The likely causes were social comparison with the leaderboard and the lack of autonomy (badges were mandatory).
    [summary table](https://educationaltechnologyjournal.springeropen.com/articles/10.1186/s41239-017-0042-5/tables/13)
- **Levels produce a "push, then slump".**
  - **Goes, Guo & Lin (2016, Information Systems Research)** studied a large knowledge exchange [P]. Users contribute more just *before* reaching a rank, then "contribution levels drop significantly after". The boost is temporary and smaller at higher ranks.
    [IDEAS](https://ideas.repec.org/a/inm/orisre/v27y2016i3p497-516.html)
- **Badges steer some users only.**
  - **Anderson, Huttenlocher, Kleinberg & Leskovec (WWW 2013)** showed that Stack Overflow badges steer participation and the mix of activities [P].
    [PDF](https://archives.iw3c2.org/www2013/proceedings/p95.pdf)
  - **Hoernle et al. (2020)** found that only about 20 % of users are strongly steered by a badge [P]. More than 40 % of badge earners appear unaffected, and part of the "steering" is a statistical artefact.
    [arXiv](https://arxiv.org/abs/2002.06160v1)
- **Once launched, gamification is hard to remove.**
  - **Thom, Millen & DiMicco (CSCW 2012)** found that removing points from IBM's internal social network lowered overall contribution [P].
    [IBM Research](https://researcher.watson.ibm.com/publications/removing-gamification-from-an-enterprise-sns)
  - See also Nike Run Club and Foursquare below.

### A2. Duolingo

The best-documented case. Most numbers are relative lifts from A/B tests.

| Mechanic | Result | Source |
|---|---|---|
| Leaderboards → leagues (2018–19) | Overall learning time **+17 %**; "highly engaged" learners (≥ 1 h/day, 5 days/week) roughly **tripled**. The first friends-only leaderboard did not work. Leagues of similar learners did. | Jorge Mazal (former CPO), [Lenny's Newsletter](https://lennysnewsletter.com/p/how-duolingo-reignited-user-growth) [C/S] |
| Combined growth programme (leagues + notifications + streaks) | Current-user retention +21 % over 4 years. Daily churn of the best users −40 %. DAU ×4.5. | same [C/S] |
| Streak separated from the daily XP goal | D14 retention **+3.3 %**, DAU +1 %, share of learners on a streak +10.5 % (+19 % for new learners). Higher goals were *barriers* to a habit: almost 40 % of learners who were active two days in a row without a streak had the "intense" goal. | [Duolingo blog](https://blog.duolingo.com/improving-the-streak) [C] |
| Streak tied to XP | "Overcomplicated the feature and confused users". Simplified to "one lesson a day". | [Lenny's podcast summary](https://lennysnewsletter.com/p/behind-the-product-duolingo-streaks) [C/S] |
| Streak Wager | D7 retention **+14 %** (the largest of D1/D7/D14) | Duolingo engineering via [making.duolingo.com](https://making.duolingo.com/how-streaks-keep-duolingo-learners-committed-to-their-language-goals) [C]; a talk summary says "+600 % in-app purchase revenue" [S] |
| Badge tests | Congratulating sign-ups: **0 %** on everything. Wider range of badges: +2 % DAU, +2 % D14, +5 % IAP. Tiered badges: +1 % DAU, +1 % D14. | Duolingo talk via [Econsultancy](https://econsultancy.com/six-a-b-tests-used-by-duolingo-to-tap-into-habit-forming-behaviour/) [S] |
| Visible streak; streak shown after each lesson | +3 % DAU / +1 % D14; +1 % DAU / +3 % D14 | same [S] |
| Gardenscapes-style "moves" counter | "Completely neutral". The team disbanded. | [Lenny's Newsletter](https://lennysnewsletter.com/p/how-duolingo-reignited-user-growth) [C/S] |
| Notifications | Brand-new reminders persuade more, but novelty wears off. A bandit algorithm with a forgetting curve was built to stop repeats. Rule: no increase in notification volume without strong justification and **CEO approval**. | [Duolingo blog](https://blog.duolingo.com/hi-its-duo-the-ai-behind-the-meme/) [C], Mazal [C/S] |

Duolingo's downsides:

- **XP farming.** Researchers at HKUST scraped about 30,000 cheating-related forum posts. One user won Diamond league 90 weeks in a row by rotating nine languages and grinding low-risk exercises. Duolingo responded with cool-downs and fewer high-XP activities.
  [Links I Would Gchat You](https://linksiwouldgchatyou.substack.com/p/when-gamification-goes-too-far) [O/S]
- **Leagues are not for everyone.** Duolingo itself says "Leaderboards aren't for everyone" and offers an opt-out (by making the profile private).
  [Duolingo blog](https://blog.duolingo.com/duolingo-leagues-leaderboards/) [C]

### A3. Strava

- **Local Legends (2020)** rewards **consistency rather than speed**: the most efforts on a segment in a rolling 90 days. Its leaderboard is a histogram of "how many more efforts to catch the leader", not a ranked list of times. It is subscriber-only.
  [iMore](https://www.imore.com/strava-announces-local-legends-new-way-compete-segments) [C via press]
  This is the closest analogue to MySpeedPuzzling's split between "activity-based" and "skill-based" competition.
- **The 2020 paywall** moved full segment leaderboards behind the subscription (the top 10 stayed free). It caused an "eruption" of complaints, but Strava kept it.
  [BikeRadar](https://www.bikeradar.com/news/strava-leaderboards-routes-subscription/), [Cycling Weekly](https://cyclingweekly.com/news/latest-news/the-strava-dilemma-458194) [O]
- **Scale of social and challenge mechanics:**
  - 71 M challenge joins in Jan–Sep 2020.
  - Over 1 M athletes joined a single monthly 5K challenge.
  - 9.6 bn kudos in 2021.

  [Strava year-in-sport coverage](https://triathlonmagazine.ca/training/strava-year-end-report-32-billion-km-covered-and-9-6-billion-kudos-given/) [C via press]
- **Goals:** 96 % of Summit subscribers who set a goal in January were still active in July (2019 report) [C via press]. This is correlational.
- **Trophy Case:** shows finished challenges only, newest four on the profile. Badge art is refreshed every year "to keep the community motivated".
  [Strava support](https://support.strava.com/hc/en-us/articles/216918557-The-Strava-Trophy-Case), [It's Nice That](https://www.itsnicethat.com/news/illo-strava-graphic-design-illustration-200123) [C]
- **No public data** links Local Legends or badges to conversion.

### A4. Chess.com and Lichess

- **Chess.com Leagues (2021/22):**
  - **Deliberately decoupled from rating.** "Sometimes you need a different kind of motivation."
  - Points come from rated wins and draws, whatever the opponent's strength.
  - Divisions of 50 players reset weekly.
  - There are 8 leagues, and the share promoted shrinks as you climb.
  - **No demotion** ("you can never lose a level").
  - You can leave at any time.
  - Sandbagging is punished under fair-play rules.

  [Announcement](https://www.chess.com/news/view/chesscom-releases-leagues), [Support](https://support.chess.com/en/articles/8557800-what-is-the-players-league) [C]
- **Lichess** keeps only rating plus a few rare, hand-awarded trophies. A Lichess blog argues that added mechanics serve engagement rather than the player.
  [Lichess FAQ](https://lichess.org/faq) [C/O]

### A5. Achievement hunters: Steam, Xbox, PlayStation

- **Xbox Gamerscore** is the sum of achievement values. It is not a separate currency.
  - Rules: 1,000 G at launch, +1,000 G every 6 months, at most 5,000 G per game, at most 200 G per achievement.
    [Engadget](https://www.engadget.com/2013-11-15-all-xbox-one-games-follow-same-policies-for-achievements.html), [Digital Citizen](https://www.digitalcitizen.life/more-than-300-xbox-games-add-305000-easy-gamerscore-through-new-updates/) [C/S]
  - Inflation problem: in mid-2026, 305 cheap "shovelware" games each added 1,000 easy G.
    [Pure Xbox](https://www.purexbox.com/news/2026/07/300plus-xbox-games-have-injected-300000g-worth-of-easy-achievements) [O]
  - Lesson: an aggregate score gets inflated by easy achievements unless points are weighted and capped.
- **EEDAR (2007)** found that Xbox 360 games with more, more varied and *visible (non-secret)* achievements had higher review scores and sales [S]. This is correlational: bigger games make longer lists.
  [Shacknews](https://shacknews.com/article/49489/study-claims-xbox-360-achievements)
- **Steam global unlock percentages:**
  - Many players never finish games. A blog analysis puts the median game at about 36 % finished.
    [TwoAverageGamers](https://www.twoaveragegamers.com/game-completion-rates/) [O]
  - Unlock rates follow a log-normal distribution across about 15k games [P, arXiv preprint].
    [arXiv 2404.15295](https://arxiv.org/abs/2404.15295)
  - Showing rarity (the % of players who own an achievement) is standard practice for hunters [O].

### A6. Fitness and others

- **STEP UP trial (Patel et al., JAMA Intern Med 2019)** [P]:
  - Design: 602 adults over 36 weeks. All arms had points and levels; one arm each added support, collaboration or competition.
  - All three gamified arms beat the control. Competition was the largest: **+920 steps/day**.
  - **Only the competition arm stayed significantly above control** after the game was switched off.

  [Penn Medicine](https://pennmedicine.org/news/news-releases/2019/september/using-a-wearable-device-to-exercise-more-add-competition-to-improve-your-results)
- **Peloton:**
  - Club Peloton tiers (Bronze 0–99 … Legend 30,000+).
  - Badges are recalculated retroactively when ceilings rise, which caused sudden tier jumps and confusion.
  - Badges are separate per workout type.

  [Club Peloton](https://www.onepeloton.com/en-CA/club-peloton), [The Clip Out](https://theclipout.com/club-peloton-points-recalculation-new-badge-tiers/) [C/O]. No retention data is public.
- **Nike Run Club (2016)**: a redesign dropped trophies and friend challenges. The App Store rating fell from 4.5 to 1.5, and trophies were later restored ("Trophies and Achievement badges are back!").
  [The Drum](https://www.thedrum.com/news/nikes-redesign-its-popular-running-app-angers-users) [O]
- **Foursquare (2014–15):**
  - At about 50 M users, mayorships became "nearly impossible", points stopped reflecting achievement, and badges "stopped feeling special" as more were added.
  - Moving badges and mayors into Swarm triggered backlash. Mayorships came back in 2015, scoped to friends and the last 30 days.

  [VentureBeat](https://venturebeat.com/ai/foursquares-new-swarm-app-means-the-death-of-badges-mayors/), [Engadget](https://www.engadget.com/2015-06-24-foursquare-swarm-split.html), [Next](https://nextconf.eu/2014/08/the-de-gamification-of-foursquare/) [O/C]
- **Untappd**: critics say badges turned beer culture into "ticking" unique check-ins and may encourage heavier drinking. No removal was found.
  [Brews News](https://brewsnews.com.au/the-anti-social-social-beer-app) [O]
- **Goodreads reading challenge**: there is no controlled study.
  - In one reader's data, books were about 15 % shorter in challenge years.
    [HackerNoon](https://hackernoon.com/why-you-should-stop-your-reading-challenge-oy8p3yxf) [O]
  - Press reports describe numeric goals as "anxiety-inducing" for some readers and helpful for "numbers people".
    [CBC](https://www.cbc.ca/1.7077257) [O]

  This is relevant to piece-count-based XP: count-based goals push people towards smaller or faster items.
- **Pokémon GO medals** (Bronze → Platinum per activity, with tier gaps such as 100 → 50,000 PokéStops): no study isolates their effect. Surveys point to achievement as one of seven motives, and intrinsic motives as the most durable.
  [Bulbapedia](https://bulbapedia.bulbagarden.net/wiki/List_of_medals_(GO)), [Social Science Quarterly 2021](https://ideas.repec.org/a/bla/socsci/v102y2021i1p547-551.html) [P/O]

### A7. What backfired: summary

| Failure mode | Evidence |
|---|---|
| Overjustification (control rather than information) | Deci et al. 1999 [P]; Hanus & Fox 2015 [P] |
| Leaderboard demotivation at the bottom | The evidence is **mixed** [P]. Low ranks hurt some people (especially those high in openness) and spur others. Tiered or relative leaderboards help both high and low positions. Being close to a page or tier boundary motivates. [Emerald 2023](https://www.emerald.com/insight/content/doi/10.1108/intr-12-2021-0897/full/html), [WSU dissertation](https://rex.libraries.wsu.edu/esploro/outputs/doctoral/LEVELING-THE-COMPETITION-THE-IMPACT-OF/99901221152701842), [ISB](https://www.isb.edu/faculty-and-research/research-directory/the-monetary-and-social-effects-of-sales-contest-leaderboards) |
| Streak anxiety | Silverman & Barasch [P]: an *intact* streak led 66.2 % to do another exercise, a *broken* one 57.9 %, even though the real behaviour was identical. The effect is worse when people blame themselves and is softened by "streak repair". [INSEAD Knowledge](https://knowledge.insead.edu/marketing/consumer-streaks-are-motivating-key-keeping-them-alive) |
| Notification fatigue | Localytics 2017 survey (self-reported) [O/S]: 10 % disable notifications at 1/week, 37 % at 2–5/week; 22 % would stop using the app at 2–5/week. YouGov 2021 [S]: 47 % of US adults get "too many"; 39 % turn them off entirely, 8 % delete the app. [YouGov](https://business.yougov.com/content/36287-how-consumers-feel-about-push-notifications-2021), [MarketingProfs](https://www.marketingprofs.com/charts/2018/33486/how-many-mobile-push-notifications-are-too-many) |
| Farming and gaming the metric | Duolingo XP farming [O/S]; Local Guides spam (C3); Reddit karma farming (C3) |
| Pay-to-win and paywall perception | Strava's 2020 leaderboard paywall backlash [O]. Duolingo gems buy XP boosts, which feeds the farming critique [O]. |
| Inflation and dilution at scale | Foursquare badges and mayorships [O]; Xbox shovelware G [O] |
| Removing a system later | Thom et al. 2012 [P]; Nike 2016; Foursquare 2014 |

### A8. Free → paid conversion

- **Evidence that gamification *causes* conversion is thin.**
  - **Bapna et al. (MIS Quarterly 2018, Last.fm)** used matched difference-in-differences [P]. *Paying* led to more engagement: +287 % songs, +15.8 % friends, +2 % forum posts. The causal arrow can run from payment to engagement.
    [MISQ](https://aisel.aisnet.org/misq/vol42/iss3/4)
  - One 2026 quasi-experiment on streaks reported 6.9× trial-to-paid conversion, but it was not randomised and the authors themselves call the effects associational [O/P-lite].
    [citedrive](https://www.citedrive.com/en/discovery/an-experimental-analysis-of-gamifications-impact-on-customer-engagement-and-retention-in-subscription-based-business-models/)
- **Duolingo monetises *around* gamification rather than through badges:**
  - streak freezes and repairs
  - gems
  - the wager (+600 % IAP reported) [S]
  - "Wider range of badges +5 % IAP" [S]

---

## B. Several progression currencies: keeping them understandable

- **There is no published study of "how many currencies users can hold".**
  - Industry consensus [O]: each extra currency raises confusion and churn *at the moment it is introduced*. Designers introduce currencies gradually, after onboarding.
    [PocketGamer.biz](https://www.pocketgamer.biz/is-the-rise-of-engagement-focused-in-game-currencies-confusing-player), [Udonis](https://www.blog.udonis.co/mobile-marketing/mobile-games/balanced-mobile-game-economy)
  - Cognitive science [P]: working memory holds about **4 chunks** (Cowan 2001, "The magical number 4"), so people can hold roughly 3–4 *families* of things, not 5+ unrelated counters.
- **How the reference products structure it** (each currency has its own job and its own place):

| Product | Currencies | How they stay distinct |
|---|---|---|
| **Duolingo** | XP (effort) → feeds weekly **league** (competition); **streak** (habit); **gems** (spendable); achievements/badges | XP is the *only* earned score; the league is a *view* of weekly XP, not a new number. Gems are spent, never compared. Coupling the streak to XP was undone because it confused people [C/S]. |
| **Stack Overflow** | **Reputation** (one number = trust → privileges); **badges** (gold/silver/bronze collection) | Reputation is earned only from *others' judgement* (votes, accepted edits) and unlocks capabilities. Badges are a separate collection with a fixed 3-metal colour code. [Reputation rules](https://stackoverflowteams.com/help/whats-reputation) [C] |
| **Xbox** | Achievements → **Gamerscore** (sum) | The score is *derived* from the collection, so there is one concept with two views. Hard caps per game and per achievement [C]. |
| **Strava** | Kudos (social), Trophy Case (challenge badges), KOM/QOM (speed, all-time), Local Legend (frequency, 90-day rolling) | Each has its own icon (crown vs laurel) and its own surface (feed, profile, segment page). Speed and consistency are two titles, never summed. [C] |
| **Clash Royale** | Trophy Road (permanent) vs Ranked / Path of Legends (seasonal) | In 2022 Supercell *stopped resetting the permanent Trophy Road* and moved seasonal competition into a separate ranked mode. Badges from past seasons are kept. [Supercell](https://supercell.com/en/games/clashroyale/blog/news/what-will-happen-to-your-trophies-2) [C] |
| **Chess.com** | Rating (skill) vs Leagues (activity, weekly) | Leagues are explicitly "regardless of rating". No demotion. [C] |
| **Peloton** | Badges → Club Peloton points → tiers | Retroactive recalculation caused surprise jumps. A derived score must be stable or announced. [O] |

- **Patterns that recur** (practice, not experimental evidence):
  1. **One earned score per motivation.** Effort, collection, competition and trust are four motivations. Anything else is a *view* of one of them, such as a weekly slice, a level or a sum.
  2. **Derived beats parallel.** Gamerscore = Σ achievements; a Duolingo league = a weekly slice of XP. Users learn one thing and see it twice.
  3. **Spendable and earned currencies never share a name or icon** (Duolingo XP vs gems).
  4. **Permanent and seasonal are separate objects with separate names** (Clash Royale, Strava KOM vs Local Legend, Chess.com rating vs league).
  5. **Fixed colour semantics.** Stack Overflow's gold, silver and bronze appear *only* on badges. Duolingo's league gems/colours appear only in leagues.
  6. **Each currency has a home surface.** The others appear there only as a link. Stack Overflow shows rep and the badge count on every user card, but privileges only on the privileges page. Strava shows Local Legend only on segments and activities.
- **Explainer hub.** Stack Overflow's help pages ("What is reputation?", "Privileges", "Badges") are the canonical example. Each answers: what it measures, how you earn or lose it, what it unlocks, where you see it [C]. Duolingo explains leagues in-context, on the league screen itself, with a banner for tournaments [C].

---

## C. Community-contribution reputation ("karma")

| System | What earns it | Reviewer reward? | Negative? | Privileges? | Notes and evidence |
|---|---|---|---|---|---|
| **Stack Overflow** | Votes from others. **Suggested edit +2 only when accepted, capped at +1,000 per user**. Max 200 rep/day from votes and edits. | **No reputation for reviewing**, only badges (Reviewer/Steward). This led to "robo-reviewing", answered with **audits** (known-bad items seeded into queues) and review bans. Some users collected dozens of bans. | Small: −2 per downvote, −1 to the voter, −100 at 6 spam flags. Floor is 1. | Yes. Review queues at 350 / 500 / 1,000 rep; edit without review at 1,000+ (thresholds vary by site). | [Reputation](https://stackoverflowteams.com/help/whats-reputation), [Review intro](https://stackoverflowteams.com/help/reviews-intro), [Edit badges blog](https://stackoverflow.blog/2014/10/07/new-editing-badges-and-enhancements-to-suggested-edits/), [Meta on audits](https://backiee.wasmer.app/https_meta_stackexchange_com/a/403670) [C/O] |
| **Reddit karma** | Upvotes | n/a | Yes (downvotes) | Subreddits gate posting on karma thresholds | Thresholds created an industry of **karma farming**: reposts and generic comments, then resale of accounts for spam. [BotDefense wiki](https://redlib.hbubli.cc/r/antibotarmy/wiki/index) [O] |
| **Wikipedia** | No points. Edit count is visible; barnstars are given by people. | — | — | Edit count informally weighs in rights requests | **Barnstars** [P]: top-1 % editors given a barnstar had **+60 % median productivity** over 90 days and were less likely to quit (Restivo & van de Rijt 2012, [PMC](https://pmc.ncbi.nlm.nih.gov/articles/PMC3315525)). **Symbolic awards for newcomers** [P]: "sizeable" retention effect lasting 4 quarters (Gallus 2017, Management Science, [INFORMS](https://pubsonline.informs.org/doi/fpi/10.1287/mnsc.2016.2540)); about 20 % one-month lift per a secondary summary [S]. The community's essay: edit counts are "quick and crude… high edit counts are sometimes indicative of carelessness" ([Wikipedia:Edit count](https://Wikipedia.com/wiki/Wikipedia:EC)). |
| **MusicBrainz** | No points | — | Rejected edits | **Voting needs an account older than 2 weeks, a verified e-mail and more than 10 accepted edits.** Auto-editor status is granted. | Edits stay open 7 days or until 3 unanimous yes votes. The Code of Conduct notes that auto-editor rights became a "status symbol". A 2019 proposal names the problem "many editors don't receive any vote". [Docs](https://musicbrainz-docs-development.readthedocs.io/en/latest/introductions/voting.html) [C] |
| **Discogs** | Submissions graded by votes ("Entirely Incorrect" … "Correct and Complete") | Voting rights are granted *implicitly* for good behaviour | **Contributor Improvement Program**: a quiet restriction until enough "correct" votes, rather than a negative score | Voting | [Database FAQ](https://support.discogs.com/hc/en-us/articles/360004936634-Database-FAQ) [C] |
| **Waze Map Editor** | Edit counts (about 3k for level 2; 25k / 100k / 250k / 500k for levels 3–6) | — | Blocked from levelling up for careless mass edits | Edit rights per rank. **Promotion is manual** (support or mentors) and can come early for quality. | [Waze forum](https://www.waze.com/discuss/t/how-do-i-get-to-level-2-wme/95797), [Wazeopedia](https://wazeopedia.waze.com/wiki/UAE/Template:Editing_rank) [C/O] |
| **iNaturalist** | Identification counts; "Top identifiers" counts only Research Grade observations | — | — | — | Staff "not particularly interested in gamifying… can encourage poor user behavior". Users report "gamers" mass-agreeing to climb boards. A proposal would split IDs into Leading / Improving / Supporting / Maverick to reward *leading* IDs. [Forum (staff)](https://forum.inaturalist.org/t/gamify-accuracy-award-value-to-quality-not-just-quantity/14428/36) [C/O] |
| **OpenStreetMap / StreetComplete** | No official points; StreetComplete counts quests | — | — | — | OSM contributors perceive game elements as "not effective" (Chen 2017 thesis, [UWSpace](https://uwspace.uwaterloo.ca/handle/10012/11770)) [P-lite] |
| **Google Local Guides** | Points per contribution (review, photo, edit; **add a place = 15**, the highest), 10 levels, Level 10 = 100,000 points | — | — | Perks (historically) | Widely described as "easily manipulated". Point-chasing produces fake or duplicate photos and bogus reviews; "fake looking level 10 accounts". [YouGov/SEL](https://yougov.com/articles/27007-local-guide-programs-gamification-encourages-point), [Local Search Forum](https://localsearchforum.com/threads/local-guides-are-posting-bogus-photos-reviews.50987), [TechCrunch 2019](https://techcrunch.com/?p=1502573) [O/C] |
| **BoardGameGeek GeekGold** | Paid per *approved* submission (new game 2–5, image ~1, correction 0.25–3; quality affects the amount) | **Pennies: approve vote ≈ 0.01, decline ≈ 0.05.** A deliberate asymmetry *against* rubber-stamping (wiki values are approximate). | — | GeekGold is spendable | [BGG wiki](https://boardgamegeek.com/wiki/page/Geekgold) [O/C] |
| **Untappd** | Badges for check-ins | — | — | — | Quantity of unique check-ins over quality [O] |

**Takeaways from C.** Most of these are practice. The barnstar and Gallus results are the strongest evidence.

1. **Reward accepted outcomes, not submissions.** Stack Overflow, BGG and Discogs all pay only after review, and Stack Overflow caps the edit reward. Local Guides pays on submission and is the most-abused system.
2. **Reviewers: recognition, not points per review.** Stack Overflow gives badges only and still needed audits. BGG pays almost nothing and pays *more* for declining. iNaturalist refuses. When a reward per review exists, someone optimises the count, and the result is rubber-stamping.
3. **Negative feedback works better as a quiet restriction than a public minus score.** Discogs CIP, Waze's blocked promotion and Stack Overflow review bans are examples. Stack Overflow's −2 per downvote is small, and the floor is 1.
4. **Privileges are the real reward in data communities.** Stack Overflow, MusicBrainz, Discogs and Waze tie trust to *capabilities*, and promotion at the top is often human (Waze, MusicBrainz auto-editors).
5. **Personal human recognition measurably helps** [P]: barnstars +60 % among top editors; symbolic newcomer awards lift retention.
6. **Leaderboards by raw count reward volume and easy items** (iNaturalist, Wikipedia, Waze warnings, Untappd).
7. **Decay is rare for contribution reputation.** Stack Overflow, Wikipedia and Waze have none; Strava's Local Legend uses a rolling window for *activity titles*. A "last 12 months" view is the usual compromise.

---

## D. Seasonal competitive systems

| System | Season | Reset | All-time status kept separately? |
|---|---|---|---|
| Duolingo leagues | **Weekly** (Sunday, local time) | Promotion/demotion between **10 leagues**; groups of about 30 matched by similar study habits and time zone; Diamond tournament for the top 10 | XP total is permanent; the league is a weekly slice. [C](https://blog.duolingo.com/duolingo-leagues-leaderboards/) |
| Chess.com Leagues | **Weekly**, 50-player divisions | Promotion only, **no demotion**; promotion share 20 → 15 → 10 → 5 → 3 → 1 | Rating untouched, explicitly separate. [C](https://support.chess.com/en/articles/8557800-what-is-the-players-league) |
| Strava | Monthly challenges; Local Legend = **rolling 90 days** | Challenges end monthly; Legend title moves continuously | KOM/QOM all-time, separate icon. [C] |
| Clash Royale | Monthly ranked season | Seasonal ranked resets; **permanent Trophy Road no longer resets (2022)** | Yes, permanent ladder plus seasonal mode; season badges kept. [C](https://supercell.com/en/games/clashroyale/blog/news/what-will-happen-to-your-trophies-2) |
| Rocket League | About **3 months** (varies) | **Soft reset**: MMR is hidden, not wiped; 10 placement matches | Season reward titles persist. [C/O](https://www.rocketleague.com/news/competitive-season-6-changes-season-5-rewards/) |
| Fortnite Ranked | Per season (a few months) | Partial (and later tested full) reset; **floor protection** (no demotion below Elite within a season) | Per-mode ranks. [C](https://www.epicgames.com/help/en-US/c-Category_Fortnite/c-Fortnite_Gameplay/how-do-i-play-fortnite-ranked-mode-a000086834) |

**Patterns:**

- **Season length follows usage frequency:**
  - weekly for daily-habit apps (Duolingo, Chess.com)
  - monthly for exercise (Strava)
  - about 3 months for skill ladders (Rocket League, Fortnite)
- **Seasons compete on activity in small, matched cohorts** (Duolingo 30, Chess.com 50) rather than one global list. This is what made Duolingo's leaderboard work after the friends-only version failed [C/S].
- **All-time skill is never reset; only the seasonal view is** (Clash Royale's 2022 split, Chess.com rating vs league).
- **Loss is softened:**
  - no demotion (Chess.com)
  - floors (Fortnite)
  - soft resets (Rocket League)
  - streak and league "shields" (Duolingo)

  Loss aversion makes demotions feel about twice as bad as equal gains.
- **Season results become permanent mementos** (season badges and titles), so the reset does not erase the past.

---

## E. Mobile UI best practice for gamification

**Celebration hierarchy (practice).**

- The only published *celebration* data is Duolingo's [S]: emphasising the streak *after each lesson* gave +1 % DAU and +3 % D14.
- Celebrating sign-ups did nothing (0 %). Celebrate *meaningful progress*, not trivia.
- Practice ladder:
  1. **Inline**: an XP counter ticks up and the progress ring fills on the result card. Use this for every routine gain.
  2. **Toast or summary card**: one per action, combining everything earned ("+120 XP · New: Bronze …"). Never several toasts in a row.
  3. **Modal or full screen**: only for rare milestones (a level band, a top tier, the end of a season). It must be skippable, never block the core action, and never be stacked.
- Asana's "celebration creatures" appear *randomly*, not every time, to stay special [O] ([Zapier](https://zapier.com/blog/asana-celebrations/)).

**Progress visuals.**

- **Endowed progress (Nunes & Drèze 2006)** [P]: with the same 8 required washes, a card showing 2 of 10 already stamped led to 34 % completion, against 19 % for an empty card of 8.
  [Wharton](https://knowledge.wharton.upenn.edu/podcast/knowledge-at-wharton-podcast/the-lowdown-on-customer-loyalty-programs-which-are-the-most-effective-and-why/)
- **Goal-gradient (Kivetz, Urminsky & Zheng 2006, JMR)** [P]: effort accelerates near the goal.
- Practical consequence: always show *the next* reachable goal (the nearest level or achievement tier) as a bar or ring with the remaining amount. Goes et al. show the slump that follows reaching a goal, so the next goal should appear immediately.

**Badge cabinets.**

- Common elements: the newest few on the profile with "see all" (Strava shows 4); greyed locked items with progress (Duolingo achievements, Steam); rarity % (Steam); tiers that share one icon with different metal frames (Pokémon GO, Stack Overflow).
- Duolingo's own blog called profile-buried achievements "hard to find, and harder to celebrate" [C].
  [Duolingo blog](https://blog.duolingo.com/achievement-badges)

**Profile density.**

- Keep 1–2 status signals on compact surfaces (player cards, leaderboard rows). Stack Overflow shows rep plus the badge count; Strava the crown or laurel only where it is relevant.
- Full detail belongs on the owner's progress page.

**Accessibility.**

- **WCAG 2.2.2 Pause, Stop, Hide (AA)**: auto-moving content lasting more than 5 s needs a pause control.
- **WCAG 2.3.3 Animation from Interactions (AAA)**: honour `prefers-reduced-motion` (techniques C39/SCR40). Confetti is named as a typical offender.
  [W3C](https://w3c.github.io/wcag/understanding/animation-from-interactions)
- Always announce the outcome in **text** (an `aria-live` region). Treat the animation as decoration hidden from assistive technology. canvas-confetti has `disableForReducedMotion`.

**Avoid dark patterns.**

- The EU **Digital Fairness Act** consultation (July–October 2025) targets "gamification… reward loops and countdowns" and addictive design. 70 % of respondents favoured binding rules, and most wanted such features *off by default*.
  [Sidley](https://datamatters.sidley.com/2025/08/01/eu-consults-on-digital-fairness-act-big-changes-ahead-for-consumer-facing-platforms/), [Slaughter and May](https://thelens.slaughterandmay.com/post/102m222/digital-fairness-act-european-commission-publishes-responses-to-consultation)
- Specific things to avoid:
  - loss-framed or guilt notifications ("you'll lose your rank")
  - confirmshaming on opt-out
  - countdown pressure
  - paid shortcuts to competitive standing
  - surprise recalculation of public scores (the Peloton jumps)
- Notification budget: Duolingo needs CEO sign-off for more volume. The Localytics survey shows opt-outs rising steeply above 1 per week.

---

## Implications for MySpeedPuzzling

### 1. Five independent concepts is one or two more than people can hold. Present three families.

| Family | Contains | Question it answers | Free / members |
|---|---|---|---|
| **Progress** (personal, never competitive) | XP → Level 1–50 | "How much have I puzzled?" | Free |
| **Collection** | Achievements (tiers) + their **score** | "What have I done?" | Earned by all; showcase for members (see 4) |
| **Competition** | **Season standing** (today's "Points") + **Rank** (all-time skill tier/rating) | "How fast am I, this season and overall?" | As today |

**Karma is not a fourth peer currency.** It belongs to the *catalogue / community* context, the way Stack Overflow's privileges and Wikipedia's barnstars do. Show it only on the contributor's profile section, in the review tools and on a contributors page. Never put it in the main progress strip. See 5.

### 2. Naming: make each word mean one thing

- **"Points" is the most generic word in the set**, and it collides with "XP" (experience *points*) and "Achievement *Points*". Rename:
  - competitive Points → **"Season points"** or a noun (e.g. "Ladder score")
  - Achievement Points → **"Achievement score"** (Gamerscore-style)

  Then "points" appears once.
- **Achievement score is derived** (Σ of achievement values, like Gamerscore), so it is not a new currency. Weight it by difficulty or rarity and cap it per category, so easy or bulk achievements cannot inflate it (the Xbox shovelware problem).
- **Reserve metal names (Bronze → Diamond) for achievement tiers only.** Skill tiers, leagues and levels must not reuse them; Stack Overflow uses gold, silver and bronze only for badges. If seasonal competition later gets divisions, give them a different naming scheme.
- **"Karma" carries Reddit connotations** (up/down votes, farming). A descriptive name fits better, such as "Catalogue contributions" / "Curator level", with role badges ("Catalogue helper", "Reviewer").

### 3. One colour plus one icon per family, and a home surface for each

- Progress: one hue, ring icon. Collection: metal frames. Competition: a trophy or stopwatch hue. Community: a distinct hue and icon.
- Home surfaces:
  - XP and level: the post-solve card and own profile header
  - Achievements: the cabinet
  - Season and Rank: leaderboards and the puzzle page
  - Contributions: the moderation pages and the contributor section
- Elsewhere, at most a compact chip that links home.
- **Never show all five on one player card.** Suggested card content: level ring + Rank chip. Everything else goes on the profile.

### 4. Gating achievements behind membership

- Evidence on gamification → conversion is weak, and paywalled status mechanics drew backlash at Strava. Removing or hiding systems after launch is costly (Nike, Foursquare, Thom 2012).
- Recommendation:
  - **Everyone earns achievements and sees their own progress**, including locked items with progress bars. Endowed and goal-gradient effects are the conversion lever.
  - **Membership unlocks the public showcase**, rarity statistics, the full cabinet, pins on the profile, and possibly the score's ladder.
- Message it honestly ("You've earned 7 — members can show them on their profile"). Never say "you'll lose them". The badges must not be held hostage.

### 5. Karma / contribution design (strongest evidence: Stack Overflow, BGG, Wikipedia, Local Guides)

- **Credit only accepted proposals** (change and merge requests that are approved, or closed as "outdated" because the fix was already applied). Nothing on submission.
- **Weight by impact, with a cap.** A merge of duplicate puzzles is worth more than a typo fix. Also cap per day or week, as Stack Overflow does (+2 per edit, max 1,000; 200/day).
- **Moderators get no per-review points.** Rubber-stamping and racing for queue items are the documented failure mode. Instead:
  - a **role badge plus a visible count of decisions** on their profile (as recognition, not a score)
  - periodic **personal thanks** (barnstar-style, from Jan or peers; +60 % productivity [P])
  - if any reward exists, give it only for decisions that are *not later reversed*
- **No visible negative karma.** Use quiet rate limits for players whose proposals are mostly rejected (Discogs CIP style).
- **Use karma as trust, not just status.** Later, unlock privileges such as "suggest without a photo", a faster queue, or a moderator invitation. Promotion to moderator stays a human decision (Waze, MusicBrainz).
- **A contributors leaderboard, if any:** last 12 months, accepted contributions only, top N plus "you". Avoid a raw-count all-time board (iNaturalist and Wikipedia "editcountitis").

### 6. XP and levels

- **Award XP for real puzzling, not for anything farmable.** Exclude times awaiting verification, cap repeats of the same puzzle per day, and make sure duplicate and copy detection also removes XP.
- **Scale XP so that big puzzles are not punished.** The Goodreads lesson (count goals lead to shorter books) maps to count-based XP pushing players towards 100-piece puzzles. Scale by pieces or effort, not per solve.
- **Expect a slump after each level-up** (Goes 2016). Always show the next level *and* the nearest achievement tier, and use a smooth curve so levels 40–50 are not dead zones.
- **No public XP leaderboard.** Keep XP non-competitive as intended; competition lives in Season / Rank. Duolingo's XP-fed leagues produced XP farming.

### 7. Seasons for the competitive "Points"

- **Monthly (or quarterly) seasons** fit puzzling frequency better than weekly. Duolingo and Chess.com are daily-habit products; Strava, which is closer to MSP's cadence, runs monthly challenges.
- **Rank (all-time skill tier/rating) never resets** (Clash Royale's 2022 split). The season is a separate object named "Season …".
- **Compete in matched groups** (country, piece-count class, similar rank band) rather than one global list of 12k players. This was Duolingo's fix, and Foursquare's mayorships died at scale.
- **No demotion pain.** Use Chess.com-style promotion-only tiers or a soft reset. **Season results become permanent mementos** (season achievements). Offer an opt-out from season standings, as Duolingo and Chess.com do.

### 8. Celebrations after logging a time

- **One summary card**: "+86 XP · Level 12 (64 %) · New: 500-piece Silver · Season: #14 in CZ". Animate it inline.
- **Modal only** for:
  - every 5th or 10th level
  - Gold-and-above achievement tiers
  - the end of a season
- Honour `prefers-reduced-motion`, keep a text equivalent in `aria-live`, and provide a pause control for anything that moves longer than 5 s (WCAG 2.2.2). This is already the pattern in the live-feed ring.
- **Notifications:** batch progression news into the weekly digest Jan already chose; no per-achievement pushes or e-mails (consistent with the Localytics thresholds); never loss-framed.

### 9. Explainer hub ("How progress works")

- **One page with three or four cards**, one per family in its colour and icon. Each card answers:
  - what it measures
  - how you earn it (and lose it, if ever)
  - where you see it
  - free or members
  - how it differs from its neighbours, e.g. "XP rewards doing, Rank rewards speed, Season points reward speed *this month*"
- **Link the "?" next to every chip** to its card (Stack Overflow pattern).
- **Introduce progressively:**
  - XP and level at launch for all
  - achievements at the first earned badge
  - Season after the first ranked result
  - contribution recognition only after the first accepted proposal

  The industry warning is that churn spikes when a currency is introduced; staggered reveals spread that out.

### 10. Launch risk

- Gamification systems are **hard to roll back** (Thom 2012, Nike, Foursquare). Ship the smallest set first (XP/levels + achievements), then Season, then contribution recognition.
- **Measure** D7/D30 retention of new players, solves logged per active player, and free → trial → paid conversion against a holdout where feasible. Duolingo's badge tests showed effects of 0–2 %, so expect small effects and test rather than assume.
