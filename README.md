<div align="center">

# MessageGlobe Sync for PrestaShop

**The official PrestaShop module for [MessageGlobe](https://messageglobe.com)** —
sync your customers into MessageGlobe contact lists and deliver every store email over SMTP.

[![Latest release](https://img.shields.io/github/v/release/Message-Globe/messageglobe-presta?sort=semver)](https://github.com/Message-Globe/messageglobe-presta/releases/latest)
[![CI](https://github.com/Message-Globe/messageglobe-presta/actions/workflows/ci.yml/badge.svg)](https://github.com/Message-Globe/messageglobe-presta/actions/workflows/ci.yml)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-8.1%2B-df0067?logo=prestashop&logoColor=white)](https://www.prestashop.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)

</div>

---

MessageGlobe Sync connects your store to the [MessageGlobe](https://messageglobe.com) messaging
platform and does two jobs, each optional:

- **Contact sync** — add your customers (email + phone + name) to a MessageGlobe contact list,
  automatically as they register or change, and in bulk for your existing catalog.
- **Reliable email** — route every outgoing PrestaShop email (order confirmations, account emails,
  etc.) through the MessageGlobe SMTP relay for better deliverability.

The contact-sync layer is built on the official
[MessageGlobe PHP SDK](https://github.com/Message-Globe/messageglobe-php) — bundled under `vendor/`,
so nothing needs Composer on the server — and syncs are queued for background processing so the
storefront and back office never block on the network. Email delivery uses the module's own
dependency-free SMTP client, so no PHPMailer is bundled.

## Table of contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Contact sync](#contact-sync)
- [Bulk sync](#bulk-sync)
- [Cron queue](#cron-queue)
- [Email takeover (SMTP)](#email-takeover-smtp)
- [Security & privacy](#security--privacy)
- [How it works](#how-it-works)
- [Development](#development)
- [FAQ](#faq)
- [License](#license)

## Features

| Feature | What it does |
|---|---|
| **Contact sync** | Add customers (email, phone, first/last name) to a MessageGlobe contact group when they are created or updated. |
| **Bulk sync** | One-click **Sync all customers** with batched, parallel AJAX requests and a progress bar. |
| **Cron queue** | Background queue with retry/backoff and a recent-runs log — reconcile large catalogs without timeouts. |
| **SMTP email takeover** | Route all outgoing PrestaShop email through the MessageGlobe relay, with automatic fallback to PrestaShop's mailer if a send fails. |
| **SMTP connection test** | Verify the host and credentials without sending an email. |
| **Built on the SDK** | REST (Contacts) runs on the official MessageGlobe PHP SDK; email uses a dependency-free SMTP client — no PHPMailer bundled. |

## Requirements

- PrestaShop **8.1 → current**
- PHP **7.4+**
- A [MessageGlobe account](https://dashboard.messageglobe.com) and an access token
- A MessageGlobe **contact group** (list) UID to sync into

## Installation

### From a release (recommended)

1. Download **`messageglobesync-x.y.z.zip`** from the
   [latest release](https://github.com/Message-Globe/messageglobe-presta/releases/latest) — **not**
   the "Source code" archive (that one is rooted at `messageglobe-presta-x.y.z/` and PrestaShop
   rejects the folder name).
2. In the back office go to **Modules → Module Manager → Upload a module**, choose the zip, then
   install and configure it.

The release zip bundles the SDK (`vendor/`), so nothing needs Composer on the server.

### From source (developers)

```bash
git clone https://github.com/Message-Globe/messageglobe-presta.git modules/messageglobesync
```

The folder **must** be named `messageglobesync`. Then install **Message Globe Contact Sync** from
the Module Manager. No Composer step is required — the SDK is committed under `vendor/`.

## Configuration

Open the module's configuration page and set:

- **Access token** — your MessageGlobe API bearer token.
- **Group ID** — the UID of the MessageGlobe contact list (group) to sync into.
- **Cron token** — a secret guarding the cron URL. Leave blank and save to auto-generate one.

## Contact sync

Customers are synced to your MessageGlobe group with these fields, when available:

- `email` (from the customer record)
- `phone` (from the customer's most recent non-deleted address, preferring `phone_mobile` over
  `phone`)
- `first_name` / `last_name`

Sync runs automatically when a **customer is created** or **updated**, and on demand via
[bulk sync](#bulk-sync) or the [cron queue](#cron-queue). The module keeps a local mapping between
each PrestaShop customer and their MessageGlobe contact UID.

Because the MessageGlobe Contacts API exposes create and delete endpoints, an update is applied as
**delete the previous remote contact, then create a new one** with the current data. If a customer
ends up with neither an email nor a phone, their remote contact is removed to keep the list clean.

## Bulk sync

Use **Sync all customers** on the configuration page to push existing customers to MessageGlobe. To
avoid long-running requests, work is split into small AJAX batches issued from the browser with a
live progress bar.

Defaults:

- Batch size: `10` customers per request
- Parallel requests: `4`

If Cloudflare or your host blocks requests, lower the batch size or parallel count; raise them
carefully if your server handles it well.

## Cron queue

For large catalogs, use the background queue instead of relying on the browser.

- Click **Queue all customers for cron sync** to enqueue every customer.
- Copy the generated **Cron URL** and call it on a schedule (every minute is recommended).

```bash
curl -s "https://your-shop.example.com/module/messageglobesync/cron?token=YOUR_TOKEN"
```

Optional batch-size override:

```bash
curl -s "https://your-shop.example.com/module/messageglobesync/cron?token=YOUR_TOKEN&limit=25"
```

Each run processes a small batch and exits quickly. New and updated customers are enqueued by hooks,
so storefront and back-office requests stay fast. Failed jobs are retried automatically with
exponential backoff up to the configured maximum, and the configuration page shows the most recent
cron runs.

## Email takeover (SMTP)

The module can optionally **take over all outgoing PrestaShop email** and deliver it through the
MessageGlobe SMTP relay. Enable **Route outgoing emails through Message Globe** in the *Email
takeover* panel and configure:

- **Host** (default `dashboard.messageglobe.com`)
- **Port** (`465` for SSL/SMTPS, `587` for STARTTLS)
- **Encryption** (SSL, STARTTLS, or none)
- **Username** and **password / API token**
- Optional forced **From** address and name (many relays require the sender to match the
  authenticated domain)

Use **Test connection** to connect and authenticate against the server **without sending an email**
and confirm the credentials before enabling the takeover — leave the password blank to test the
saved one.

When enabled, the module intercepts the `actionEmailSendBefore` hook, re-renders the PrestaShop mail
template (both the HTML and the matching `.txt` part, into a `multipart/alternative` message) and
sends it through the relay. **If a send fails for any reason, the module automatically falls back to
PrestaShop's default mailer** so no message is lost, and the error is recorded via `PrestaShopLogger`.

## Security & privacy

- The **cron token** protects the cron endpoint; a run with a missing or wrong token is rejected.
- The **SMTP password** is never rendered back into the form — a blank submit keeps the saved value.
- The module only sends the data you configure (the contact fields above, and the emails PrestaShop
  was already going to send) to MessageGlobe. Nothing is shared with any other third party.
- API and delivery failures are logged through `PrestaShopLogger`.

## How it works

- **PHP SDK for REST.** Contact create/delete calls go through the bundled MessageGlobe PHP SDK
  (`vendor/messageglobe/sdk`), loaded by a small PSR-4 autoloader — no raw cURL in the module.
- **Dependency-free email.** The SMTP takeover uses the module's own SMTP client, so PHPMailer is
  never bundled and cannot collide with PrestaShop's own mail library (Swift Mailer on 8.x, Symfony
  Mailer on 9.x).
- **Async by default.** New/updated customers are written to a queue table and drained by the cron
  worker with retry/backoff, so page requests return immediately.
- **Mapping.** A local table maps each PrestaShop customer to their MessageGlobe contact UID.

## Development

```bash
php -l messageglobesync.php   # lint (repeat per file, or use a linter)
```

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs `php -l` across the module and the
bundled SDK on PHP 7.4–8.3 and checks the module structure and version consistency on every push and
pull request.

Releases are automated: pushing a `vX.Y.Z` tag runs
[`.github/workflows/release.yml`](.github/workflows/release.yml), which builds the natively
installable `messageglobesync-X.Y.Z.zip` (a single `messageglobesync/`-rooted tree with the SDK
bundled) and attaches it to the GitHub Release. A manual run of the workflow uploads the same zip as
an artifact for testing.

To refresh the bundled SDK, copy the upstream `src/` tree (excluding `Email/` and `MessageGlobe.php`,
which pull in PHPMailer) over `vendor/messageglobe/sdk/src`.

## FAQ

**Do I need a MessageGlobe account?**
Yes — create one and copy your access token and the target group (list) UID from the dashboard.

**Does it sync when a customer is deleted, or when an address changes?**
No. Contact sync runs when a customer is **created** or **updated**. Deleting a customer or editing
an address no longer triggers a sync — run a [bulk sync](#bulk-sync) or the
[cron queue](#cron-queue) to reconcile the list when needed.

**Why prefer the release zip over GitHub's "Source code" download?**
PrestaShop requires the module folder to be named exactly `messageglobesync`. The release asset is
rooted that way; the auto-generated "Source code" archive is not.

**My contacts aren't appearing immediately.**
Automatic syncs are queued and drained by the cron endpoint. Configure a real system cron to call
the cron URL every minute for prompt processing.

## License

Built on the MIT-licensed
[MessageGlobe PHP SDK](https://github.com/Message-Globe/messageglobe-php). See the repository for the
module's license terms.
