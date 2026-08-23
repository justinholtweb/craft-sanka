---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: Nothing is being submitted, what each rejection means, and what to check first.
---

## Start here

```sh
php craft sanka/diagnostics
```

It checks everything that could be wrong, in the order it would break, and prints the fix rather
than the symptom. Most of what follows is on that screen already.

## Nothing is being submitted

In rough order of likelihood:

1. **Dry run is on.** It is on by default. The log will be full of rows saying so.
2. **There are no rules.** Saving an entry submits nothing until a rule covers it. The overview
   screen says this outright, and so does the panel on the entry.
3. **The engine is not configured.** Check the overview: an engine that is on but unconfigured says
   what it is missing.
4. **The entry is not live.** Drafts, revisions, disabled and expired entries are not submitted as
   updates — a disabled entry queues a *removal* instead, because asking an engine to index a 404
   is worse than doing nothing.
5. **The host is not reachable.** `.ddev.site`, `.test`, `localhost` and private addresses are
   refused with a reason, because every engine would refuse them too. There is a switch to override
   this for testing.
6. **The queue is not running.** Sanka pushes a drain job; something has to run it. `php craft
   sanka/queue/status` shows what is waiting.

## Google says 403

The service account is not an owner of the property in Search Console. That is nearly always all
this means. Add the service account's client email — you can see it on the settings screen — as an
**owner**, not a full user, of the property that covers the URLs you are submitting.

The other possibility is that the Indexing API is not enabled for the Cloud project.

## Google says 401

The credentials themselves. Either the key is wrong or has been revoked, or **the server clock is
out of sync** — a signed assertion is time-bound and Google rejects one from a machine whose clock
has drifted.

`sanka/diagnostics` signs an assertion locally and tells you whether that worked. If it did, the
key is fine and the problem is at Google's end of the exchange; if it did not, the problem is the
key.

## Google says 429

The daily quota is gone. It is 200 publishes per **Cloud project** per day by default, and it
resets at midnight Pacific.

Sanka should not normally let you reach this — it cuts each batch to the remaining quota — but two
Craft installs sharing one service account key share the two hundred, and Sanka can only see its
own spending.

## IndexNow says 403

The engine could not read your key file, or it did not contain the key. Open the key file URL shown
on the settings screen in a private window. It has to be publicly reachable, return plain text, and
contain exactly the key: no basic auth, no staging password, no redirect, no HTML error page
returning 200.

## IndexNow says 422

The URLs are not on the host the key covers. This is almost always a mismatch between the site's
configured base URL in Craft and the domain it is actually served on — `www` versus bare, or a
staging domain left in `.env`.

## A URL was skipped

Every skip carries its reason. The common ones:

| Reason | What it means |
|---|---|
| Cooldown | The same URL went to the same engine recently. This is working as intended — it is what stops a save storm costing quota |
| Dry run | Sanka is still decaffeinated |
| Not an absolute URL | Submissions need scheme and host |
| That host is not reachable | A development domain, or a private address |
| No site is served from that host | The URL belongs to somebody else, and the engines would say so |
| That looks like a preview URL | It carries a token, and submitting it would hand a private draft to a search engine |

## robots.txt is not changing

A static `robots.txt` in your web root is served before anything Craft routes. Sanka detects one and
says so on both the settings screen and the crawler screen. Delete the file, or leave the setting
off and render `craft.sanka.robots()` from your own `robots.twig` instead.

## llms.txt is stale

It is cached, and the cache is cleared whenever an entry is saved. If it is still stale, check the
cache duration — and remember that a section only appears if it has URLs on the site you are
looking at.

## The crawler log is empty

Either the visit log is switched off, or nothing has come. Some agents are rarer than their
reputation suggests, and two entries in the policy table — `Google-Extended` and
`Applebot-Extended` — are directives rather than crawlers and **will never appear**, because
nothing fetches anything under those names.

## Everything says "unchecked" in the crawler log

Verification is a separate pass, because forward-confirmed reverse DNS costs two DNS round trips
and cannot happen on the request being logged. Run `php craft sanka/crawlers/verify`, or put it on
a nightly cron.

## Submissions vanished from the log

Retention. The default is 90 days, and garbage collection removes older rows — except anything
still waiting to be sent, which is never pruned.
