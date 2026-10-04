---
title: FAQ
slug: faq
order: 60
summary: Common questions about instant indexing, AI crawlers and what Sanka does not promise.
---

## Will this make my pages rank better?

No, and be suspicious of anything that says otherwise. Instant indexing changes how quickly a page
is *seen*, not how well it does once it has been. What it buys you is the difference between a page
being live and a page being known about — which for a news site, a job board or anything with a
shelf life is the whole game, and for an evergreen page is worth very little.

## Is the Google Indexing API allowed for ordinary pages?

Google documents it as being for `JobPosting` and `BroadcastEvent` pages. In practice it is used far
more widely and it works. Sanka does not pretend in either direction — the settings screen says the
same thing this answer does. It is your call to make knowingly.

## Why is my quota only 200?

That is Google's default, and it is per Cloud **project**, not per site — two Craft installs sharing
one service account key share the two hundred. You can apply to Google for more.

Sanka is built around the assumption that the number is small: quota is checked before the call
rather than discovered by being refused, and the cooldown exists so an author saving repeatedly
spends one submission instead of twenty.

## Does Google support IndexNow?

No. Bing, Yandex, Seznam, Naver and Yep do. Submitting to one of them reaches all of them.

## Then why does IndexNow matter?

Because Bing's index is what ChatGPT Search and Copilot answer from. IndexNow is currently the
shortest path from *I published this* to *an answer engine knows it exists*, and it costs one HTTP
request with no credentials to obtain.

## Do I have to upload a key file?

No. Sanka serves it from a route at your site root, so there is nothing to deploy and nothing to go
stale when you regenerate the key.

## Can I still submit sitemaps to Google?

No, and neither can anything else. Google retired its sitemap ping endpoint in 2023; any tool still
offering it is selling a no-op. Sanka's sitemap engine goes over IndexNow, which still works, and
the settings screen says exactly this.

## What happens if I save an entry twenty times?

One submission. The cooldown is keyed on URL, engine and event type, and defaults to five minutes.
The other nineteen appear in the log as skipped, saying so.

## Will a failed submission break my save?

No. Submissions are drained in a queue job after the request, so an author never waits on Google
and a network failure cannot fail the save that caused it.

## What is the difference between blocking GPTBot and blocking OAI-SearchBot?

Everything. `GPTBot` collects text to train OpenAI's models — blocking it costs no visibility.
`OAI-SearchBot` builds the index ChatGPT Search cites from — blocking it removes you from those
answers and does nothing about training.

Sanka labels all 22 agents with what blocking each one actually costs, which is the main reason the
crawler screen exists.

## Is the crawler log trustworthy?

Only after verification. A user agent takes one line of `curl` to forge, so a row means something
*claimed* to be that agent until `sanka/crawlers/verify` has confirmed it by forward-confirmed
reverse DNS. Sanka distinguishes genuine, forged and unverifiable rather than collapsing them.

## Does the readiness audit use AI?

No. Twenty deterministic checks against the page's own HTML. Running it twice gives the same
answer, which is the only way a score is worth acting on. Nothing is sent anywhere.

## Will Sanka fetch arbitrary URLs?

No. The audit only fetches hosts that one of your Craft sites is configured to serve, derived from
site config rather than from anything typed into a box.

## Can I use it to serve AI crawlers different content?

Sanka will tell you an agent is a crawler, and there are honest uses for that — measurement, or
dropping a cookie banner a machine cannot dismiss. Serving *different content* is cloaking, and
every engine here penalises it.

## Is Sanka free?

Lite is, and it is not a trial: both submission engines, the console, submit-on-save, the ledger,
quota tracking and retries, forever, for nothing. It is capped at three auto-submit rules.

Pro is a one-off **$129** with a **$99/year** renewal and adds the GEO half — `llms.txt`, the AI
crawler policy, the visit log and the readiness audit — plus sitemap resubmission, scheduled
sweeps, bulk submit and CSV export. See [Installation](installation#editions) for the full table.

## What happens when the Pro renewal lapses?

Pro keeps working; renewals buy updates, as with every Craft plugin licence. Sanka also degrades
rather than breaking — the Lite feature set carries on indexing, nothing you configured is
discarded, and renewing restores exactly what was there.

## Does it work on a multi-site install?

Yes. Each site's URLs are submitted under its own host, IndexNow submissions are grouped per host
because the protocol requires it, and `llms.txt` and `robots.txt` are generated per site.

## Does it work with SEOmatic, or my own sitemap?

Yes. Sanka does not generate sitemaps — it submits whichever URLs you point it at.

Leave the sitemap table empty and it asks SEOmatic where its sitemap index actually is, per site.
That matters: SEOmatic's index lives at `/sitemaps-<groupId>-sitemap.xml` and `/sitemap.xml` only
*redirects* to it, so the obvious guess asks every IndexNow engine to follow a hop it did not need
to and records a URL in the ledger that is not the one being read. The settings screen names where
the list came from.

With no SEO plugin to ask, Sanka falls back to each site's `/sitemap.xml`. Anything you type into
the table wins over both.

If SEOmatic also generates your `robots.txt`, ask `craft.sanka.robots()` for only the AI crawler
policy rather than pasting the whole file underneath. **Usage** covers it, under *Only the parts you
need from `robots()`*.
