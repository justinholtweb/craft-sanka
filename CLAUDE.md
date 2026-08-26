# Sanka — Craft CMS 5 Plugin

## Project Overview

Sanka does two jobs that share one machine: **instant indexing** (Google Indexing API, IndexNow,
sitemap resubmission) and **GEO** (llms.txt, AI crawler policy, crawler visit log, readiness audit).

Distributed as `justinholtweb/craft-sanka`. **Lite/Pro paid — Pro is $129 with a $99/year
renewal**, like `[[project_craft_caffeine]]` and `[[project_craft_heat]]`.

Pricing appears in more places than you expect: this file, `README.md`'s editions table,
`docs/installation.md`, `docs/faq.md`, the promo cover badge in `promos/slides.html`, the marketing
page seed at `justinholt/scripts/seed/plugin-pages/craft-sanka.json` — and the Craft Console
listing, where the *actual* prices live and which is the one that can silently disagree with
everything else.

Reference point for the indexing half: the WordPress plugin *Instant Indexing for Google*
(`fast-indexing-api`). The GEO half has no WordPress equivalent worth copying.

Plan in `docs/plan.md`. Family conventions and traps in the shared memory.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step. The control panel screens are plain Twig with `{% js %}`/`{% css %}` blocks.
- No runtime dependencies beyond Craft's own — `ext-openssl` for JWT signing, Craft's Guzzle for
  the transport.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\sanka`
- Package: `justinholtweb/craft-sanka`
- Handle: `sanka`

### The load-bearing idea: one ledger, many engines

Everything that could want a URL indexed — an entry save, a paste into the console, a bulk resubmit,
a sweep, a console command, a retry — writes **one row** into `sanka_submissions` and stops.
`Dispatcher` is the only thing that reads them and the only thing that calls an engine.

That is the whole architecture. Quota, deduplication, cooldown, batching, backoff and the audit trail
are therefore written once and behave identically no matter what caused the submission. Adding a
fourth engine means writing an engine, not touching the dispatcher.

`skipped` is a first-class outcome with a message on it, never a silent drop. Almost every support
question about a tool like this is "why didn't it send my page", and the answer must be on the screen.

### Engines

`EngineInterface` asks the same six questions of each: am I usable, what notification types do I
take, how many URLs per call, what is my daily allowance, when does that allowance reset, and what
happened. `BaseEngine::sameForAll()` exists so a batch that fails as a whole still writes an answer
onto every row — a row that went out and came back with nothing on it is the one state the ledger is
not allowed to contain.

### Services

- `engines` — the registry. The transport is settable, which is what lets the checks exercise the
  whole path with no network.
- `submissions` — the ledger. Queueing, deduplication, cooldown, status transitions, retention.
- `dispatcher` — drains it. Contains no engine-specific code.
- `quota` — per engine per day. Update-then-insert, not an upsert helper: the update has to be an
  *increment*, and the unique index on `(engine, quotaDate)` is what makes the race safe.
- `rules` — decides whether an element change is worth spending quota on. Pure; queues nothing.
- `urls` — the one place that decides whether a URL may be submitted at all. Syntactic only.
- `crawlers` — the agent registry, the policy, the robots.txt render, the hit log, FCrDNS.
- `llms` — llms.txt generation and caching.
- `geo` — the readiness audit.

### Editions

`models/Edition.php` is the single auditable statement of the boundary, taking `bool $isPro` so it
can be read and tested without an application. Settings **refuse** a Lite-impossible configuration on
save (so the operator is told); services **downgrade** on read (so a lapsed licence degrades instead
of breaking, and upgrading restores exactly what was configured).

## Hard rules

1. **Nothing leaves the server in dry run**, and dry run is on by default on a fresh install.
2. **Quota is checked before the call, not after the failure.**
3. **A save storm must not cost quota** — cooldown, keyed on url+engine+type.
4. **Credentials never reach the log, the ledger or a template.** `Settings::credentialSummary()` is
   what the settings screen shows.
5. **Sanka fetches its own site and nothing else.** The audit's target is derived from Craft's site
   config, never from user input, so there is no SSRF surface to fence off.

## Traps found while building this

- **`craft\db\Migration` has no `createUniqueIndex()`.** It is `createIndex(null, $table, $cols, true)`.
- **MySQL commits implicitly on DDL**, so a migration that fails half way leaves its finished tables
  behind and Yii's rollback cannot take them back — the retry then fails on the first table it
  already made. `Install::safeUp()` drops first for exactly this reason.
- **`craft\console\ControllerTrait` already defines public `note()`, `tip()`, `success()`,
  `failure()` and `warning()`**, and `yii\base\Controller` defines `render()`. A *private* method of
  any of those names is a fatal compile error the moment the class is autoloaded — not at the call
  site. Renamed to `hint()` and `printReport()`.
- **`Elements::EVENT_AFTER_DELETE_ELEMENT` fires after the `elements` row is gone on a hard delete**,
  so writing a submission row carrying `elementId` violates the foreign key and takes the deletion
  down with it. A soft delete leaves the row in place. `Submissions::write()` checks the row still
  exists before keeping the id — done there because it is the only place rows are written.
- **Datetime columns hold UTC and read back as site-local.** The dispatcher is safe because it
  compares `Db::prepareDateForDb(new DateTime())` against the stored value — UTC on both sides — but
  anything *displaying* one has to convert explicitly, and the first version of the cooldown message
  showed a time seven hours out. The integration check for the backoff had the same bug in reverse.
- **Project config writes are buffered until the request ends.** A bare script that saves settings
  and then makes an HTTP request to its own site has to call `ProjectConfig::flush()`, or the web
  request reads the old configuration, the routes are never registered, and the files 404.
- **`writeYamlAutomatically` makes that worse on a shared harness**: the yaml files become newer than
  the config, so the next web request starts applying them, takes the project-config lock, and races
  whatever the script does next. The checks turn it off for the duration.
- **Project config strips empty arrays**, so a saved rule comes back missing `entryTypes`, `sites`
  and `engines`. `Rule::fromConfig()` treats a missing key and an explicitly empty one differently on
  purpose — unticking every event box means "nothing", and defaulting that to "everything" would do
  the exact opposite of what was asked.
- **A DOMNodeList is live.** Removing `<script>` nodes while iterating one skips every other match;
  `iterator_to_array()` first.
- **Craft's `Response::FORMAT_RAW` plus an explicit `Content-Type`** is what makes `/llms.txt` and
  the IndexNow key file come back as `text/plain` — a `.txt` route inside Craft otherwise inherits
  the site default, and IndexNow's engines are strict about it.
- **Google's batch responses are not ordered.** Parts are matched by `Content-ID` (returned prefixed
  with `response-`), never by position, and a part that is missing entirely is retried rather than
  assumed sent.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-sanka/tests/integration/checks.php   # 162 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-sanka/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks switch the plugin to Pro, rewrite its settings in memory, and restore both on the way out
— with retries, because the project-config lock is process-wide. Everything they create is prefixed
`sanka-check-` and swept on the way out, including strays from a run that died half way.

`tests/support/FakeHttpClient.php` is the transport seam. Its default responder answers Google's
token endpoint *and* synthesises a correct `multipart/mixed` batch response from whatever the request
actually contained — a batch response that did not answer the URLs sent would make the dispatcher's
checks pass for the wrong reason.

## Icons

Three files, and they are not interchangeable:

- **`src/icon.svg`** — the colour app tile (brass `#D9A441`, white mark), 100 viewBox. This is what
  Craft shows in the plugin listing and what the family ships; every other `craft-*` plugin does the
  same. The mark is placed with a `<g transform>` rather than a nested `<svg>`, because Craft
  sanitises the file before inlining it and a stripped inner `<svg>` leaves nothing but a brass
  square. Verified: the rect, the transform, both paths and `fill-rule="evenodd"` all survive.
- **`src/icon-mask.svg`** — the monochrome silhouette Craft masks for the CP nav. No tile, no colour.
- **`promos/assets/icon.svg`** and the marketing site's `web/images/plugins/sanka.svg` are
  byte-identical copies of `src/icon.svg`. Changing the icon means updating all three.

## Coding conventions

- `Craft::t('sanka', '…')` for user-facing strings; `src/translations/en/sanka.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Engine failures are *results on a row*, not exceptions. An exception means there is no row to write
  the answer onto.
- Every failing GEO finding carries a remediation. A check that cannot say what to do about a failure
  is not worth running.
