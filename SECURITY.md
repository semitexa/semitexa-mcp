# Security Policy

## Reporting a vulnerability

**Do not open a public issue for a security problem.** A public report tells
everyone who runs this framework about the hole at the same moment it tells us.

Report it privately, either way:

- **GitHub Security Advisories** — [open a private report](https://github.com/semitexa/semitexa-mcp/security/advisories/new).
  This keeps the discussion, the fix and the eventual advisory in one place, and
  nothing is visible until we publish it.
- **Email** — `support@semitexa.com`. Put "security" and the package name
  (`semitexa/mcp`) in the subject.

Include whatever you have: the version you are on, what you did, and what
happened. A minimal reproduction is worth more than a long description, but send
the description if that is what you have — an unclear report is better than a
report nobody sends.

## What to expect

This framework is maintained by a small team, so these are commitments we can
actually keep rather than the numbers that look best:

| | |
|---|---|
| We acknowledge your report | within 3 working days |
| We tell you whether we can reproduce it | within 7 working days |
| We agree a disclosure date with you | before anything is published |

If a report turns out not to be a vulnerability we will say so plainly, and say
why. If it is one, you will be credited in the advisory unless you ask not to
be.

## Which versions get a fix

Releases are date-based (`YYYY.MM.DD.HHMM`) and cut from `master`, often several
times a week. **Only the latest release is supported.** There are no maintenance
branches for older versions, and there is no long-term-support line: a fix ships
in the next release, and upgrading is how you get it.

`semitexa/ultimate` pins every internal `semitexa/*` package to an exact version,
so a consumer who installs through it moves the whole framework together rather
than one package at a time.

## Scope

In scope: anything in `semitexa/mcp` that lets a client reach beyond the tools a
provider declares, run outside `APP_ENV=dev`, act outside the project's own
directory and services, or read what the project keeps secret (`.env`, keys,
tokens) through a tool, a resource or an error message.

Out of scope, and said explicitly so nobody spends an evening on it:

- an agent you connected doing what its tools allow: changing the project is what
  they are for, and why the server runs in dev only;
- findings that require an attacker who already has code execution on the host,
  or access to `.env`;
- the `dev-*` branches; report against a released version;
- denial of service through resource exhaustion by a client you connected
  yourself.
