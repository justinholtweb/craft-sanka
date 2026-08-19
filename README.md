# Sanka

**Instant indexing and GEO for Craft CMS.** Push URLs to Google's Indexing API and IndexNow the
moment they change, then manage, log and audit how AI search engines read your site.

Named for the instant coffee, which is the joke — and which also names the setting you should leave
on for the first week: Sanka runs **decaffeinated** by default, recording exactly what it *would*
have sent while nothing at all leaves the server.

Requires Craft CMS 5.3+ and PHP 8.2+.

---

## The problem

Publishing is fast. Being *found* is not. A new page waits for a crawler that turns up on its own
schedule — days, sometimes weeks — and for the answer engines it is often not a wait at all, because
they never come.

Sanka closes both gaps, and they are genuinely two different jobs.

**Indexing** is telling a search engine that a URL changed, instead of waiting to be asked. Google
has an API for it. Bing, Yandex, Seznam, Naver and Yep share one between them, called IndexNow.

**GEO** — generative engine optimisation — is the other half. Answer engines do not crawl like
search engines and are not managed like them, and almost nobody knows which of the twenty-odd "AI
bots" in their logs is which. Sanka makes that explicit: who may crawl, what they are given, who
actually turned up, and whether a page is written in a shape an engine can quote.

---

## What it does

### Submits, the moment something changes

Save an entry and the URL is queued. Publish, update, unpublish, delete — each becomes the right
kind of notification, to the engines you chose, for the sections you chose.

- **Google Indexing API** — `URL_UPDATED` and `URL_DELETED`, service-account auth, real
  `multipart/mixed` batching (100 URLs in one round trip), and quota tracking against the 200-a-day
  default that resets at midnight Pacific.
- **IndexNow** — one submission reaching Bing, Yandex, Seznam, Naver and Yep. Bing's index is what
  ChatGPT Search and Copilot answer from, so this is the shortest path from *published* to *an
  answer engine knows it exists*. The key file is served from a route, so there is nothing to upload
  and nothing to redeploy when the key changes.
- **Sitemap resubmission** *(Pro)* — one call that can move thousands of pages, which is what you
  want after a migration rather than spending a day's quota one URL at a time.

### Never spends quota by accident

Google gives you two hundred submissions a day. Spending them by accident is the normal failure mode
of every tool in this category, so three things are built in rather than bolted on:

- **Quota is checked before the call, not after the failure.** The batch is cut to fit what is left.
- **A save storm costs one submission, not twenty.** A cooldown keyed on URL + engine + type absorbs
  an author saving repeatedly while they edit.
- **Dry run is on by default** and records what would have been sent.

### Says why, every time

Every submission is a row in a ledger, and `skipped` is a first-class outcome with a message on it —
not a silent drop. "Why didn't it send my page?" always has an answer on the screen: the cooldown,
the quota, the dry run, a rule that did not match, a URL no engine could reach.

Failures are translated rather than reported. A 403 from Google says *add the service account as an
owner of the property in Search Console*. A 403 from IndexNow says *the engine could not read your
key file*. A 422 says *the site's configured base URL does not match the domain it is served on*.

### Manages the AI crawlers *(Pro)*

Twenty-two agents, each labelled with **what blocking it actually costs**, because "block the AI
bots" is advice that destroys traffic when followed literally:

| | |
|---|---|
| **Answer engines** | `OAI-SearchBot`, `ClaudeBot`, `Claude-SearchBot`, `PerplexityBot`, `DuckAssistBot`, `YouBot`, `Applebot`, `Amazonbot` — blocking one removes you from that engine's answers, and does nothing about training. |
| **User requests** | `ChatGPT-User`, `Perplexity-User`, `Claude-User` — a person just asked about this page. Blocking one tells that reader it could not be read. |
| **Model training** | `GPTBot`, `Google-Extended`, `Applebot-Extended`, `CCBot`, `Bytespider`, `meta-externalagent`, and more — blocking these costs no visibility. This is the group most people actually mean. |

Output is `robots.txt`, which Sanka will serve for you or hand to your own template as
`craft.sanka.robots()`.

### Tells you who actually came *(Pro)*

Every request from a recognised AI agent is recorded — which one, what it read, what it got. "Is
ChatGPT reading my site, and what is it reading?" stops being a guess.

Rows say *something claimed to be this agent*, because a user agent is one line of curl to forge.
`php craft sanka/crawlers/verify` does forward-confirmed reverse DNS in batches and marks each one
genuine, forged, or unverifiable — three different claims, kept apart.

### Publishes `llms.txt` *(Pro)*

A sitemap tells a crawler what exists. `llms.txt` tells a model what the site is *about* and which
pages are worth reading — a curated map, in Markdown, at a fixed path, that a model can take in one
fetch instead of crawling a thousand URLs to find the six that matter. `/llms-full.txt` is the same
map with the text inlined, for sites whose whole value is the writing.

Both are generated from Craft's own content and cached, so neither can drift from what the site says.

### Audits whether a page is quotable *(Pro)*

Twenty deterministic checks across four dimensions, scored, remediation-first:

- **Structure** — one H1, a sane heading outline, an answer-first opening, passage-sized paragraphs,
  lists and tables an engine can lift whole.
- **Attribution** — author, `datePublished`, `dateModified`, publisher.
- **Machine readability** — JSON-LD present and of a useful `@type`, question-and-answer shapes,
  canonical, no accidental `noindex`.
- **Citability** — concrete numbers, outbound sources, definitional sentences.

Nothing is sent anywhere and nothing is scored by a model, so running it twice gives the same
answer. The HTML comes from a **same-origin** fetch of a URL derived from Craft's own site config, or
from HTML you paste in — a URL typed by a user is never fetched.

---

## Getting started

```sh
composer require justinholtweb/craft-sanka
php craft plugin/install sanka
```

Then, in **Settings → Sanka**:

1. **Leave dry run on.**
2. **IndexNow** — turn it on and generate a key. That is the whole setup; Sanka serves the key file.
3. **Google** — create a service account in a Google Cloud project with the **Indexing API** enabled,
   paste its JSON key, and then — the step everybody misses — **add the service account's client
   email as an owner of the property in Search Console**. A 403 means you have not done this.
4. **Add a rule.** Sanka ships with auto-submit on and *no rules*, so it never starts spending quota
   on its own. A rule says: this section, these events, these engines.
5. Run `php craft sanka/diagnostics`. It checks everything that could be wrong, in the order it
   would break.
6. Watch the **Submissions** screen for a week, then turn dry run off.

### Cron

```sh
* * * * * php craft sanka/queue/drain          # send what is waiting
0 3 * * * php craft sanka/crawlers/verify      # confirm which crawlers were genuine
0 4 * * * php craft sanka/queue/sweep          # Pro: catch anything the save hook could not see
```

The drain also runs automatically as a queue job after a save, so the cron entry is a safety net for
sites whose queue runner is not guaranteed.

---

## Console commands

| Command | What it does |
|---|---|
| `sanka/diagnostics` | Everything that could be wrong, in the order it would break |
| `sanka/queue/status` | What is waiting, and what would stop it going out |
| `sanka/queue/drain` | Send it |
| `sanka/queue/retry-failed` | Put every failed row back in the queue |
| `sanka/queue/sweep` | *(Pro)* Queue anything that changed recently and was not submitted |
| `sanka/submit/url <url>` | Submit one URL |
| `sanka/submit/section <handle>` | *(Pro)* Submit every live entry in a section |
| `sanka/submit/sitemaps` | *(Pro)* Resubmit the sitemaps |
| `sanka/submit/status <url>` | Ask Google what it last heard about a URL |
| `sanka/crawlers/summary` | *(Pro)* Which AI agents have been reading the site |
| `sanka/crawlers/verify` | *(Pro)* Forward-confirmed reverse DNS over unchecked visits |
| `sanka/crawlers/robots` | *(Pro)* Print the robots.txt Sanka would serve |
| `sanka/geo/llms` | *(Pro)* Print llms.txt |
| `sanka/geo/audit <url>` | *(Pro)* Audit one page |
| `sanka/geo/section <handle>` | *(Pro)* Audit a section, worst first |

---

## Twig

```twig
{{ craft.sanka.robots() }}            {# the crawler policy, for your own robots.twig #}
{{ craft.sanka.llms() }}              {# llms.txt, if you would rather render it yourself #}
{{ craft.sanka.llms(null, true) }}    {# llms-full.txt #}
{{ craft.sanka.indexNowKeyUrl() }}

{% if craft.sanka.isCrawler() %}…{% endif %}
{% set agent = craft.sanka.crawler() %}
{% for row in craft.sanka.history(entry) %}{{ row.engine }} — {{ row.status }}{% endfor %}
```

Serving an AI agent *different content* is cloaking and every engine here penalises it. Measurement,
and dropping a cookie banner a machine cannot dismiss, are fine.

---

## Editions

| | Lite | Pro |
|---|---|---|
| Google Indexing API | ● | ● |
| IndexNow | ● | ● |
| Submit console, submit on save, the ledger, quota, retries | ● | ● |
| Auto-submit rules | 3 | unlimited |
| Sitemap resubmission | | ● |
| `llms.txt` and `llms-full.txt` | | ● |
| AI crawler policy and `robots.txt` | | ● |
| AI crawler visit log and verification | | ● |
| GEO readiness audit | | ● |
| Scheduled sweeps, bulk submit, CSV export | | ● |

Lite **refuses** to save a configuration it cannot run, so you are told rather than quietly given
something else. It **downgrades** on read, so a lapsed Pro licence keeps indexing with the Lite
feature set instead of breaking, and upgrading restores exactly what was configured.

---

## Things worth knowing

- **Google documents the Indexing API as being for `JobPosting` and `BroadcastEvent` pages.** In
  practice it is used far more widely and it works. Sanka says so on the settings screen rather than
  pretending in either direction; it is your call to make knowingly.
- **The 200-a-day quota is per Google Cloud *project*, not per site.** Two Craft installs sharing one
  key share the two hundred.
- **Google retired its sitemap ping endpoint in 2023.** Any tool still offering it is selling a
  no-op. Sanka's sitemap engine goes over IndexNow, which still works.
- **Google does not participate in IndexNow**, and IndexNow's engines have no Google-style quota —
  only a rate limit.
- **A static `robots.txt` in your web root beats anything Craft routes.** Sanka detects one and says
  so, rather than letting a setting appear to work and do nothing.
- **Nothing on a development domain will ever be submitted.** `.ddev.site`, `.test`, `localhost` and
  private addresses are refused with a reason, because every engine would refuse them too. There is
  a switch to override that for testing.

---

## Testing

Integration checks run inside a Craft install:

```sh
php /path/to/craft-sanka/tests/integration/checks.php
```

162 checks, self-cleaning, everything prefixed `sanka-check-`. Every engine is exercised through a
fake transport, so the whole submission path — dispatcher, ledger, quota, batching, backoff, result
interpretation — is covered without a single outbound request.

---

## Licence

Proprietary. See `LICENSE.md`.
