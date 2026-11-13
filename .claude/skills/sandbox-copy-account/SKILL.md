---
name: sandbox-copy-account
description: Copy a real player's account from production (myspeedpuzzling.com) to the sandbox (sandbox.myspeedpuzzling.com) so they sign in there with the same e-mail and password. Use when the user says "copy <email> to the sandbox", "copy account from prod to sandbox", "give <someone> access to the sandbox with their account", "move/clone this player to sandbox".
argument-hint: "<email> [more e-mails…]"
allowed-tools: Bash, Read
---

# Copy an account from production to the sandbox

Production (`myspeedpuzzling.com`) and the sandbox (`sandbox.myspeedpuzzling.com`) are two separate live installations
on the same server, lily (docs/features/sandbox/README.md "Copying a real account"). This copies ONE person's sign-in
and basic profile from production into the sandbox; afterwards they sign in on the sandbox with their production
e-mail and password.

## Run it

One command per e-mail address:

```sh
ssh root@lily.srv.thedevs.cz /srv/msp-sandbox/copy-account.sh 'person@example.com'
```

Quote the address. Several people → one call each. The command prints only the result, never the password hash.

## What it does

- Production's read-only `myspeedpuzzling:sandbox:export-account` is piped straight into the sandbox's
  `myspeedpuzzling:sandbox:import-account`, inside the server - the hash never leaves the box.
- **Copied:** e-mail, password hash, verified flag, name, player code (a new code when the sandbox has it taken),
  country, language, time zone. **Never** results, collections, pictures, private data.
- **E-mail not on the sandbox yet** → `account created`.
- **E-mail registered on the sandbox already** → it keeps everything it has there and only gets the production password
  (`was registered here already, now has the production password`).
- Everybody on the sandbox becomes a member at their first sign-in - nothing to do.

## Answers

| Output | Meaning / what to tell the user |
|---|---|
| `[OK] … account created …` | Done - they sign in at https://sandbox.myspeedpuzzling.com with their production e-mail + password |
| `[OK] … now has the production password` | They had a sandbox account; its password is now the production one |
| `Exported … (no password - signs in by e-mailed link)` | Production account without a password (Google/Apple/… only): copied, they sign in on the sandbox by e-mailed link (sandbox mail is real) |
| `No account with that e-mail` | Nobody on production has that address - check the spelling / ask the user |
| `A made-up player of the sandbox…` | `test-user+…@myspeedpuzzling.com` addresses are the sandbox's own made-up players - refused |
| ssh `Permission denied` / too many authentication failures | use `root@` (as above); the box rate-limits new ssh connections - wait 30 s |

## Never

- Never run `export-account` on its own into a terminal, a file or a log - its output contains the password hash.
- Never copy through the internal API or any HTTP call (production's API must never hand out password hashes).
- Never copy in the other direction (sandbox → production), and never write to production here - the export is
  read-only.
- Never copy results or other data "while at it" - the sandbox holds no real data beyond the copied account.
