# silvershop/newsletter-mailchimp

[Mailchimp](https://mailchimp.com/) ESP adapter for
[`silvershop/newsletter`](https://github.com/RVXD/silvershop-newsletter). It implements the core's
`NewsletterProvider` contract with a **thin Guzzle client** (no heavy official SDK) and maps subscriber changes to
the Mailchimp Marketing API v3, plus a webhook back to the local list.

The core owns capture, consent and double opt-in; this adapter just syncs confirmed subscribers to a Mailchimp
audience and keeps the local status in step via webhooks.

## Requirements

- `silvershop/newsletter` `dev-main` (the core — not on Packagist, see **Installation**)
- `guzzlehttp/guzzle` ^7
- `silverstripe/framework` ^6, `silverstripe/cms` ^6, PHP 8.3 / 8.4

`silvershop/newsletter` hard-requires `symbiote/silverstripe-queuedjobs`, so the queued sync runner comes in
transitively.

## Installation

`silvershop/newsletter` is an RVXD module (not published to Packagist), so it is resolved from a composer
`repositories` entry. The shipped `composer.json` uses the **VCS** form:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/RVXD/silvershop-newsletter.git" }
]
```

Then:

```bash
composer require silvershop/newsletter-mailchimp:dev-main
```

(A private newsletter repo needs a GitHub token with `repo` scope, e.g. `composer config -g github-oauth.github.com <token>`.)

### Local development (core not yet pushed)

When you are developing both modules side by side and `silvershop/newsletter` isn't published yet, point the
adapter at your local checkout with a **path** repository instead of (or in addition to) the VCS entry — this is
how the dev site and the standalone clean-room resolve it:

```json
"repositories": [
    { "type": "path", "url": "../silvershop-newsletter" }
]
```

## Configuration

Credentials come from the environment (`.env`):

```
SS_MAILCHIMP_API_KEY="your-api-key-us21"
SS_MAILCHIMP_LIST_ID="your-audience-id"
SS_MAILCHIMP_WEBHOOK_SECRET="optional-shared-secret"
```

The **datacenter** is derived from the API key automatically — it is the suffix after the last `-` (e.g. a key
ending `-us21` talks to `https://us21.api.mailchimp.com/3.0/`). A key with no `-<datacenter>` suffix is rejected
with a clear error. `SS_MAILCHIMP_LIST_ID` is the **Audience ID** (Audience → Settings → *Unique id*).

Then make Mailchimp the active provider:

```yaml
SilverShop\Newsletter\Provider\NewsletterProviderRegistry:
  active: mailchimp
```

Optionally map subscription merge fields to Mailchimp **merge tags** (which must already exist on the audience —
first name / surname are mapped natively to `FNAME` / `LNAME`):

```yaml
SilverShop\Newsletter\Mailchimp\MailchimpProvider:
  merge_field_map:
    Locale: 'MMERGE3'
```

> **Configure the Mailchimp audience as SINGLE opt-in.** The core already performs double opt-in (it emails the
> subscriber a confirm link and only syncs them once confirmed). The adapter therefore sends new members with
> `status: subscribed`. If the audience is *also* set to double opt-in, subscribers would receive a second
> confirmation email. If you would rather Mailchimp run the confirmed-opt-in step itself, set `double_opt_in: true`
> and the adapter sends `status: pending` instead:
>
> ```yaml
> SilverShop\Newsletter\Mailchimp\MailchimpProvider:
>   double_opt_in: true
> ```

## How it maps

| Operation | Mailchimp Marketing API v3 |
|---|---|
| subscribe / update | `PUT lists/{listId}/members/{subscriberHash}` (idempotent add-or-update) |
| unsubscribe | `PATCH lists/{listId}/members/{subscriberHash}` with `{ "status": "unsubscribed" }` |
| full resync (`sake tasks:NewsletterResync --run`) | add-or-update for every locally-subscribed record |

`subscriberHash` is `md5(strtolower(email))` — Mailchimp's member resource id. Authentication is HTTP Basic with
any non-empty username and the API key as the password.

## Webhook (Mailchimp → local)

Point a Mailchimp webhook at:

```
https://your-shop.example/newsletter/webhook/mailchimp?secret=<SS_MAILCHIMP_WEBHOOK_SECRET>
```

> **Difference from the Campaign Monitor adapter:** Mailchimp does **not** HMAC-sign its webhooks. It secures them
> with a secret embedded in the webhook URL, so this adapter validates a `?secret=…` **query param** (compared with
> `hash_equals`) rather than a signature header. If `SS_MAILCHIMP_WEBHOOK_SECRET` is unset, the webhook is accepted
> without a secret (it only ever changes status for emails already on the local list).

Mailchimp posts **form-encoded** (`type=…&data[email]=…`, one event per request). The core's webhook controller
calls this adapter's `verifyWebhook()` + `parseWebhook()`, which map Mailchimp events to the local status:

| Mailchimp event (`type`) | Local status |
|---|---|
| `subscribe` | Subscribed |
| `unsubscribe` | Unsubscribed |
| `cleaned` | Cleaned |
| `upemail` / `profile` / `campaign` | ignored |

## Development

```bash
composer install
composer run lint    # phpcs (PSR-12)
composer run stan    # phpstan (cambis/silverstan, level 5)
composer run test    # phpunit — uses a FakeMailchimpClient, no live HTTP
```

## License

BSD-3-Clause.

## Credits

Built and maintained by **Remy Vaartjes** (<remy@xd.nl>) — part of the **SilverShop-Suite**.
