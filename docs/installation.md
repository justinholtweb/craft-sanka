---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and getting your first URL submitted safely.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later, with `openssl` and `mbstring`

No other dependencies. Sanka uses the HTTP client Craft already ships.

## Install

```sh
composer require justinholtweb/craft-sanka
php craft plugin/install sanka
```

Or find **Sanka** in the Craft Plugin Store and install it from there.

## Nothing is submitted until you say so

Sanka installs in **dry run**, with **no rules**. Both are deliberate.

Dry run records every submission in the log with what *would* have been sent, and sends nothing.
No rules means saving an entry submits nothing at all. A plugin that started spending a
200-a-day quota the moment it was installed would be doing something you never asked for.

So the first thing Sanka does is nothing, visibly, until you have looked at it.

## Your first submission

1. **Turn on IndexNow.** Go to **Settings → Sanka**, switch IndexNow on and generate a key.
   That is the whole setup — Sanka serves the key file itself, from a route, so there is nothing
   to upload and nothing to redeploy when the key changes.
2. **Add a rule.** In the **Rules** table, choose a section, tick the events that should trigger a
   submission, and tick the engines that should hear about it.
3. **Save an entry** in that section.
4. **Look at Sanka → Submissions.** There is a row, marked *skipped*, saying
   *“Dry run — nothing was sent.”* That is the whole pipeline working.
5. **Run `php craft sanka/diagnostics`.** It checks everything that could be wrong, in the order it
   would break.
6. When the log looks right, turn **dry run** off.

Google takes longer to set up and needs a step outside Craft — see
[Configuration](configuration#google-indexing-api).

## Editions

Lite does the indexing. Pro adds the GEO half — the part that deals with AI search engines — plus
the bulk and scheduling tools you need once a site is big enough to have a backlog.

| | Lite | Pro |
|---|---|---|
| **Price** | **$0** | **$129**, $99/year renewal |
| Google Indexing API — submit, remove, status lookup | ✅ | ✅ |
| IndexNow — Bing, Yandex, Seznam, Naver, Yep | ✅ | ✅ |
| Submit on publish, update, unpublish and delete | ✅ | ✅ |
| Submit console — paste URLs, choose engines | ✅ | ✅ |
| The submissions ledger, with reasons for everything | ✅ | ✅ |
| Quota tracking, cooldown, retries and backoff | ✅ | ✅ |
| Dry run | ✅ | ✅ |
| Panel on the entry edit screen | ✅ | ✅ |
| `craft.sanka.*` Twig API | ✅ | ✅ |
| Auto-submit rules | 3 | unlimited |
| **`llms.txt` and `llms-full.txt`** | — | ✅ |
| **AI crawler policy** — 22 agents, rendered to robots.txt | — | ✅ |
| **AI crawler visit log**, with reverse-DNS verification | — | ✅ |
| **GEO readiness audit** — 20 checks, remediation-first | — | ✅ |
| **Sitemap resubmission** | — | ✅ |
| **Scheduled sweeps** | — | ✅ |
| **Bulk submit** by section | — | ✅ |
| **CSV export** of the ledger | — | ✅ |

Lite is not a trial. It does the whole indexing job, for nothing, forever.

## What happens when the renewal lapses

Pro keeps working. Renewals buy updates, the way every Craft plugin licence does. Sanka also
**downgrades rather than breaking**: the Pro features stop, the Lite feature set carries on
indexing, and nothing you configured is discarded — renewing restores exactly what was there.
