---
title: Usage
slug: usage
order: 30
summary: How a submission travels, the console, the ledger, bulk work, Twig and the command line.
---

## How a submission travels

Everything that could want a URL indexed writes **one row** into the ledger and stops. A save, a
paste into the console, a bulk resubmit, a sweep, a console command, a retry — by the time they
reach an engine they are indistinguishable, which is what makes the behaviour predictable.

```
queued ──▶ pending ──▶ sent
              │  ▲        recorded with the HTTP status and the engine's message
              │  └── retry, backoff doubling, capped
              ├──▶ failed   attempts exhausted, or an answer no retry could improve
              └──▶ skipped  cooldown, quota, dry run, or a rule said no
```

`skipped` is a real outcome with a reason on it, not a silent drop. *“Why didn’t it send my page?”*
always has an answer on the screen.

A drain runs as a queue job at the end of the request that queued something, so an author saving an
entry never waits on Google, and a failed submission can never fail the save that caused it.

## The overview screen

Each engine, its state, what is waiting, and what it has spent today. If something is not being
submitted, the reason is on this screen: dry run is on, the engine is not configured, the quota is
gone, or there are no rules.

## The submit console

Paste URLs, one per line. Choose engines, or leave them all unticked to use every usable one.
Choose whether the URLs were **added or updated** or **removed**.

URLs are checked before anything is queued: they must be absolute, on a host one of your sites is
served from, free of fragments, and not preview URLs. Anything else is refused here, with a
reason, rather than being spent on an engine that would refuse it too.

Submitting from this screen drains immediately rather than queueing — you pressed a button and are
watching, so you get the answer.

### Asking Google about a URL

The one read-only call Sanka makes. It is the difference between *Google accepted my notification*
and *Google acted on it*, and it costs nothing against the publish quota.

## The submissions log

Every row, filterable by status, engine and URL. Each row records what happened, how many attempts
it took, and why it was queued in the first place — so *“why did this cost me quota”* is answerable
six weeks later.

Open a row for its full history: everything Sanka has ever done with that URL, across every engine.

## Bulk work (Pro)

**Submit a whole section** from the console, or `php craft sanka/submit/section <handle>`. On a
large site this is the button that spends a day's quota, so it queues rather than sending, and the
rows go out as quota allows over the following days.

**Resubmit the sitemaps** when a change is too big to submit page by page — a migration, a re-slug,
a section going live. One call can move thousands of pages.

**Scheduled sweeps** catch what the save hook cannot see: a deployment that ran `resave`, a queue
that was down, an import, an entry going live on its post date with nobody saving anything. The
window is its own bookmark, so overlapping runs cost nothing — the cooldown absorbs them.

## On the entry screen

Every entry with a URL gets a panel showing what Sanka would do if you saved right now, and what it
last did. If no rule covers the entry, it says so — which is the answer to most *“why isn’t this
being submitted”* questions before they are asked.

## Twig

```twig
{{ craft.sanka.robots() }}            {# the crawler policy, for your own robots.twig #}
{{ craft.sanka.llms() }}              {# llms.txt, if you would rather render it yourself #}
{{ craft.sanka.llms(null, true) }}    {# llms-full.txt #}
{{ craft.sanka.indexNowKeyUrl() }}

{% if craft.sanka.isCrawler() %}…{% endif %}
{% set agent = craft.sanka.crawler() %}

{% for row in craft.sanka.history(entry) %}
  {{ row.engine }} — {{ row.status }} — {{ row.dateCreated|datetime('short') }}
{% endfor %}
```

`crawler()` returns the agent behind the request, or null. The honest uses are measurement, and
courtesy — dropping a cookie banner a machine cannot dismiss. Serving an AI agent *different
content* is cloaking, and every engine here penalises it.

## Console

| Command | What it does |
|---|---|
| `sanka/diagnostics` | Everything that could be wrong, in the order it would break |
| `sanka/queue/status` | What is waiting, and what would stop it going out |
| `sanka/queue/drain` | Send it |
| `sanka/queue/retry-failed` | Put every failed row back in the queue |
| `sanka/queue/sweep` | *(Pro)* Queue anything that changed recently and was missed |
| `sanka/submit/url <url>` | Submit one URL |
| `sanka/submit/section <handle>` | *(Pro)* Submit every live entry in a section |
| `sanka/submit/sitemaps` | *(Pro)* Resubmit the sitemaps |
| `sanka/submit/status <url>` | Ask Google what it last heard about a URL |
| `sanka/crawlers/summary` | *(Pro)* Which AI agents have been reading the site |
| `sanka/crawlers/verify` | *(Pro)* Reverse-DNS check the unverified visits |
| `sanka/crawlers/robots` | *(Pro)* Print the robots.txt Sanka would serve |
| `sanka/geo/llms` | *(Pro)* Print llms.txt |
| `sanka/geo/audit <url>` | *(Pro)* Audit one page |
| `sanka/geo/section <handle>` | *(Pro)* Audit a section, worst first |

### Cron

```sh
* * * * * php craft sanka/queue/drain          # send what is waiting
0 3 * * * php craft sanka/crawlers/verify      # confirm which crawlers were genuine
0 4 * * * php craft sanka/queue/sweep          # Pro: catch what the save hook missed
```

The drain also runs automatically after a save, so the first entry is a safety net for sites whose
queue runner is not guaranteed.
