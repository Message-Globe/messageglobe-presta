# Message Globe Sync for PrestaShop

<p align="center">
  <img src="views/img/messageglobe-logo.png" alt="Message Globe — Reach them everywhere" width="360">
</p>

[![CI](https://github.com/Message-Globe/messageglobe-presta/actions/workflows/ci.yml/badge.svg)](https://github.com/Message-Globe/messageglobe-presta/actions/workflows/ci.yml)

A PrestaShop module by **Message Globe SRL** that syncs customer email and phone
contacts to Message Globe and can route outgoing store email through the Message
Globe SMTP relay. *Reach them everywhere.*

Compatible with **PrestaShop 8.1 → current**.

The contact-sync layer is built on the official
[**MessageGlobe PHP SDK**](https://github.com/Message-Globe/messageglobe-php),
whose REST source (SMS, Contacts, Lists, Senders) is bundled under `vendor/` so
the module installs on any PrestaShop hosting without Composer. Email delivery
uses the module's own dependency-free SMTP client, so no PHPMailer is bundled.

## Features

- Adds an admin configuration page for:
  - Message Globe access token
  - Message Globe group ID
- Adds a manual **Sync all customers** button to process existing/past customers with AJAX batches and configurable parallel requests.
- Syncs customer contacts to Message Globe when:
  - a customer is created
  - a customer is updated
  - a customer address is created, updated, or deleted
  - a customer is deleted (removes the remote contact)
- Optionally routes **all outgoing PrestaShop emails** through the Message Globe SMTP relay (toggleable).
- Sends these fields to Message Globe when available:
  - `email`
  - `phone`
  - `first_name`
  - `last_name`

## Email takeover (SMTP relay)

The module can optionally **take over all outgoing PrestaShop email** and deliver it through the Message Globe SMTP relay. This is fully toggleable from the configuration page.

- Enable **Route outgoing emails through Message Globe** in the *Email takeover* panel.
- Configure the SMTP connection:
  - Host (default `dashboard.messageglobe.com`)
  - Port (`465` for SSL/SMTPS, `587` for STARTTLS)
  - Encryption (`SSL`, `STARTTLS`, or none)
  - Username and password / API token
  - Optional forced *From* address and name (many relays require the sender to match the authenticated domain)
- Use the **Test connection** button to connect and authenticate against the SMTP server (without sending an email) and confirm the host/credentials before enabling the takeover. Leave the password blank to test the saved password.

When enabled, the module intercepts PrestaShop's `actionEmailSendBefore` hook, re-renders the email template, and sends it through Message Globe using a self-contained SMTP client (no Composer/PHPMailer dependency required). Both the HTML and plain-text (`.txt`) versions of the PrestaShop mail template are rendered into a `multipart/alternative` message; if no `.txt` template exists, the plain-text part is derived from the HTML. If a send fails for any reason, the module automatically falls back to PrestaShop's default mailer so no message is lost, and the error is recorded via `PrestaShopLogger`.

## Sync behavior

- Email comes from the PrestaShop customer record.
- Phone is taken from the customer's most recent non-deleted address, preferring `phone_mobile` over `phone`.
- The Message Globe Contacts API exposes create and delete endpoints, so updates are handled as:
  1. delete the previously synced remote contact
  2. create a new remote contact with current data

## Installation

1. Copy the `messageglobesync` folder into your PrestaShop `/modules` directory (or upload the release zip from the back office).
2. Install the module from the PrestaShop back office.
3. Open the module configuration page.
4. Set your Message Globe:
   - Access token
   - Group ID
5. Click **Sync all customers** on the module configuration page if you want to send existing customers to Message Globe.

## Bulk sync performance

The manual sync avoids long-running requests by splitting work into small AJAX batches from the browser.

Default settings:

- Batch size: `10` customers per request
- Parallel requests: `4`

If Cloudflare or your hosting still blocks requests, reduce the batch size or parallel request count. If the server handles it well, increase them carefully.

## Cron queue sync

For large customer lists, use the cron-based queue instead of relying only on the browser.

### In the module configuration page

- Click **Queue all customers for cron sync** to enqueue all existing customers.
- Copy the generated **Cron URL**.
- Configure your server cron to call it every minute.

Example:

```bash
curl -s "https://your-shop.example.com/module/messageglobesync/cron?token=YOUR_TOKEN"
```

Optional batch size override:

```bash
curl -s "https://your-shop.example.com/module/messageglobesync/cron?token=YOUR_TOKEN&limit=25"
```

### Cron behavior

- Each cron run processes a small queue batch and exits quickly.
- This avoids Cloudflare/browser timeout issues on large stores.
- New and updated customers are automatically enqueued by hooks, so storefront/back-office requests stay fast.
- Historical customers can be processed in the background queue.
- Failed jobs are retried automatically with backoff, up to the configured max attempts in the module code.
- The module keeps recent cron run logs in the admin panel.

## Bundled SDK

The `vendor/messageglobe/sdk/` directory holds a copy of the REST portion of the
[MessageGlobe PHP SDK](https://github.com/Message-Globe/messageglobe-php),
loaded by a small PSR-4 autoloader in `vendor/autoload.php`. To refresh it, copy
the upstream `src/` tree (excluding `Email/` and `MessageGlobe.php`, which pull in
PHPMailer) over `vendor/messageglobe/sdk/src`.

## Notes

- The module keeps a local mapping between PrestaShop customers and the Message Globe contact UID.
- API failures are logged through `PrestaShopLogger`.

## License

Distributed under the same terms as the MessageGlobe integrations. See the
repository for details.
