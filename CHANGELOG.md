# Changelog

## 5.0.0 — 2026-08-19

Initial release. Numbered 5.x to match the Craft version it targets, as the rest of the family is.

### Indexing

- Google Indexing API: `URL_UPDATED` / `URL_DELETED`, service-account (RS256 JWT) auth,
  `multipart/mixed` batching up to 100 URLs, `urlNotifications/metadata` status lookups, and quota
  tracking against Google's 200-per-project-per-day default on a Pacific-midnight reset.
- IndexNow: key generation, key file served from a route, per-host batching up to 10,000 URLs, and
  every documented response code mapped to a meaning rather than logged as a number.
- Sitemap resubmission over IndexNow (Pro).
- One ledger behind all of them: deduplication, cooldown, exponential backoff, retry limits,
  retention, and `skipped` as a first-class outcome that always says why.
- Submit on save, delete, unpublish and restore, driven by per-section rules.
- Dry run, on by default, recording what would have been sent.

### GEO (Pro)

- `llms.txt` and `llms-full.txt`, generated from Craft's content and cached.
- A registry of 22 AI agents grouped by what they are actually for, with the cost of blocking each
  one stated, rendered to `robots.txt`.
- An AI crawler visit log, with forward-confirmed reverse DNS verification.
- A 20-check GEO readiness audit across structure, attribution, machine readability and citability.

### Everywhere

- Control panel: overview, submit console, submissions log, crawler policy and log, GEO screen, and
  a panel on the entry edit screen.
- Console commands for submitting, draining, sweeping, auditing, verifying and diagnosing.
- `craft.sanka.*` Twig variable.
- 162 integration checks.
