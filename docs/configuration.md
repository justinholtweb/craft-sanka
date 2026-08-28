---
title: Configuration
slug: configuration
order: 20
summary: Setting up both engines, writing rules, and every setting explained.
---

## Google Indexing API

Google's API is the more involved of the two, and one step happens outside Craft.

1. In **Google Cloud**, create a project and enable the **Indexing API**.
2. Create a **service account** in that project and download its **JSON key**.
3. Paste the JSON into **Settings → Sanka → Service account key**. A file path works too, if the
   web server can read it.
4. **In Google Search Console, add the service account's client email as an *owner* of the
   property.**

Step 4 is the one everybody misses, and it is what a `403` means. Sanka's error message says so
rather than printing the status code, because there is nothing else a 403 from this API is likely
to be.

### What Google says it is for

Google documents the Indexing API as being for pages carrying `JobPosting` or `BroadcastEvent`
markup. In practice it is used far more widely and it works. Sanka does not pretend in either
direction — the settings screen says the same thing this paragraph does, and the decision is yours
to make knowingly.

### Quota

The default is **200 publishes per day**, and two things about it surprise people:

- It is **per Google Cloud project**, not per site. Two Craft installs sharing one service account
  key share the two hundred.
- It resets at **midnight Pacific**, not at your server's midnight. Sanka tracks the quota day in
  Google's zone for exactly this reason.

Sanka checks the remaining quota *before* assembling a batch and cuts the batch to fit. It never
spends a request to discover the quota is gone.

## IndexNow

Switch it on and press generate. That is the setup.

The key is 8–128 characters of letters, numbers and dashes, and Sanka serves the key file from
`https://yoursite/<key>.txt` as a route — there is no file to deploy, and regenerating the key
cannot leave a stale one behind. The engines fetch that URL to confirm you control the host, so it
has to be publicly reachable: no basic auth, no staging password, no redirect.

**Endpoint** is a preference, not a fan-out. The participating engines share submissions between
themselves, so submitting to all of them would be the same submission counted five times against
five rate limits for no extra reach. Leave it on the shared endpoint unless you have a reason.

Google does not participate in IndexNow. Bing does — and Bing's index is what ChatGPT Search and
Copilot answer from, which is why this engine matters more than its reputation suggests.

## Rules

A rule is one line: **this section**, **these events**, **these engines**.

| Column | Meaning |
|---|---|
| On | Disable a rule without deleting it |
| Section | A section, or *Every section* |
| New / Changed / Removed | Which events fire it |
| Google / IndexNow / Sitemap | Where the submission goes |

With no rules, saving an entry submits nothing. Rules are matched by section **handle**, not UID,
on purpose: a rule pointing at a renamed section should stop firing loudly rather than quietly
following the rename somewhere you did not intend.

Lite keeps three rules. Configure more and the settings screen refuses the save and says why, so
you are told rather than quietly given something other than what you configured.

## Every setting

### Submitting

| Setting | Default | What it does |
|---|---|---|
| Dry run | **on** | Records what would have been sent; sends nothing |
| Submit automatically | on | Whether saving an entry queues anything at all |
| Allow unreachable hosts | off | Lets `.ddev.site`, `.test` and private addresses through. They will be refused by every engine — this is for exercising the pipeline locally |
| Cooldown | 300s | How long before the same URL may go to the same engine again |
| Retries | 4 | Attempts before a retryable failure gives up |
| Retry backoff | 60s | Wait before the first retry; it doubles each time |
| Keep submissions for | 90 days | Retention. Anything still waiting is never pruned |

**Cooldown is the setting that protects your quota.** It is keyed on URL + engine + event, so an
author who saves twenty times while editing spends one submission, not twenty. Set it to 0 and you
will notice.

### GEO (Pro)

| Setting | Default | What it does |
|---|---|---|
| Serve `/llms.txt` | off | Publishes the map |
| Serve `/llms-full.txt` | off | Publishes the map with body text inlined |
| Summary | — | The blockquote at the top of `llms.txt` |
| Sections to include | all with URLs | Which sections appear, in your order |
| Cache the generated files for | 3600s | A ceiling; saving an entry clears the cache anyway |
| Serve `/robots.txt` | off | Lets Sanka generate robots.txt from the crawler policy |
| Disallow the control panel | Automatic | Whether robots.txt names the control panel — see below |
| Extra robots.txt lines | — | Appended verbatim, after the policy and before the sitemaps |
| Log AI crawler visits | off | Records every request from a recognised AI agent |
| Keep crawler visits for | 30 days | Retention |

If a static `robots.txt` exists in your web root, it is served before anything Craft routes. Sanka
detects it and says so on the settings screen rather than letting the switch appear to work and do
nothing.

### Disallowing the control panel

Craft robots.txt files conventionally disallow the control panel. On the default `admin` trigger
that costs nothing — everybody already knows where a Craft control panel is. On a **customised**
`cpTrigger` it costs something real: the trigger was customised to keep the control panel out of
sight, and robots.txt is the most reliably fetched file on the site.

Disallowing it was never protection either way. The control panel needs a login, and a crawler that
ignores robots.txt is exactly the one you were worried about.

| Mode | What is written |
|---|---|
| **Automatic** (default) | The trigger, only when it is Craft's default `admin` |
| Always | The trigger, whatever it is |
| Never | Neither line |

`Disallow: /cpresources/` is written under Automatic and Always regardless of the trigger — it is
public, cacheable and the same on nearly every install, so it leaks nothing.

The settings screen shows exactly which lines your configuration produces.

## Permissions

| Permission | Grants |
|---|---|
| View submissions and crawler activity | The Sanka section, read-only |
| Submit URLs and retry submissions | The submit console, the retry buttons, draining the queue |
| Manage the AI crawler policy and llms.txt | Saving the crawler policy and verifying visits |
