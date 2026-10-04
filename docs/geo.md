---
title: GEO
slug: geo
order: 40
summary: llms.txt, the AI crawler policy, the visit log, and whether a page is worth quoting.
---

Generative engines do not crawl like search engines and are not managed like them. Sanka's second
half deals with them explicitly: who may crawl, what they are given, who actually turned up, and
whether a page is written in a shape an engine can quote.

All of this is Pro.

## `llms.txt`

A sitemap tells a crawler what exists. `llms.txt` tells a model what the site is *about* and which
pages are worth reading — a curated map, in Markdown, at a fixed path, that a model can take in one
fetch instead of crawling a thousand URLs to find the six that matter.

```
# Your site name

> The summary you wrote, quoted.

## Key pages
- [About](https://example.com/about): what the page is about

## Articles
- [What instant indexing does](https://example.com/…): the one-line description
```

Descriptions come from a `summary`, `description`, `excerpt` or similar field if the entry has one,
and from the opening of its own body text if it does not — which is nearly always what a
hand-written description would have said anyway.

`/llms-full.txt` is the same map with the body text inlined. It is much larger, and worth serving
only for sites whose whole value is the writing: documentation, reference, a handbook.

Both are generated from Craft's own content and cached, and the cache is cleared whenever an entry
is saved, so neither can drift from what the site actually says.

## The AI crawler policy

"Block the AI bots" is advice that destroys traffic when followed literally, because three
completely different things share the label.

**Answer engines** build the index an AI answer is written from. Blocking `OAI-SearchBot` removes
you from ChatGPT Search results and does nothing whatsoever about training.

**User requests** fetch a page because a person just asked about it. Blocking `ChatGPT-User` means
a reader who explicitly wanted your page is told it could not be read.

**Model training** crawlers collect text to train a model. Blocking these costs no visibility
today. This is the group almost everyone actually means.

Sanka knows 22 agents and labels every one with **what blocking it actually costs**:

| Purpose | Agents |
|---|---|
| Answer engines | `OAI-SearchBot`, `ClaudeBot`, `Claude-SearchBot`, `PerplexityBot`, `DuckAssistBot`, `YouBot`, `Applebot`, `Amazonbot` |
| User requests | `ChatGPT-User`, `Perplexity-User`, `Claude-User` |
| Model training | `GPTBot`, `Google-Extended`, `Applebot-Extended`, `CCBot`, `Bytespider`, `meta-externalagent`, `anthropic-ai`, `cohere-ai`, `Diffbot`, `ImagesiftBot`, `Omgilibot`, `Timpibot` |

Two of those are not crawlers at all. `Google-Extended` and `Applebot-Extended` are *directives* —
they control whether your content trains Gemini and Apple Intelligence, and blocking them has no
effect on Google Search or on Siri. They will never appear in the visit log, because nothing
fetches anything under those names.

The policy is one of Sanka's settings, so it is changed by an admin on an environment where admin
changes are allowed and deployed with project config. Anyone else with access to Sanka sees it
read-only.

The output is `robots.txt`, one group per agent. Sanka will serve it, or hand it to your own
template as `craft.sanka.robots()` — which can leave out whatever your existing `robots.txt` already
says, so a site running SEOmatic can take the crawler policy and nothing else. **Usage** covers it,
under *Only the parts you need from `robots()`*.

## The visit log

Every request from a recognised AI agent is recorded — which one, what it read, and what it got.
*Is ChatGPT reading my site, and what is it reading?* stops being a guess.

Matching costs one precompiled regular expression against the user agent and nothing at all on a
miss, which is every human request.

**A row means something *claimed* to be that agent.** Forging a user agent is one line of `curl`,
and a log that quietly treated a claim as a fact would be worse than no log. `sanka/crawlers/verify`
does forward-confirmed reverse DNS in batches — reverse the address, check the hostname belongs to
the vendor, then resolve that hostname forward and check it comes back to the same address — and
marks each visit genuine, forged, or unverifiable. Those are three different claims and Sanka keeps
them apart: a vendor that publishes no reverse records cannot be verified either way, and saying
"forged" would be a lie.

## The readiness audit

Twenty deterministic checks across four dimensions, scored, with a remediation on every finding
that is not a pass. Nothing is sent anywhere and nothing is judged by a model, so running it twice
on the same page gives the same answer — which is the only way a score is worth acting on.

**Structure** — one H1, a sane heading outline, an answer-first opening, passage-sized paragraphs,
lists and tables an engine can lift whole, enough words to be worth quoting.

**Attribution** — a named author, `datePublished`, `dateModified`, a publisher.

**Machine readability** — JSON-LD present and declaring a useful `@type`, question-and-answer
shapes, an absolute canonical, a title and description of sensible length, and no accidental
`noindex`.

**Citability** — concrete numbers, outbound sources, and at least one flat definitional sentence,
which is the shape an engine reaches for when it needs one line about the subject.

The HTML comes from one of exactly two places: a **same-origin** fetch of a URL derived from
Craft's own site configuration, or HTML you paste in. A URL typed by a user is never fetched, so
there is no request-forgery surface here to worry about.

```sh
php craft sanka/geo/audit https://example.com/a-page
php craft sanka/geo/section blog --problemsOnly
```

The section command prints the whole section worst-first, which is the order the work should be
done in.
