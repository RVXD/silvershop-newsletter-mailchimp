<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Mailchimp;

use SilverShop\Newsletter\Model\NewsletterSubscription;
use SilverShop\Newsletter\Provider\NewsletterProvider;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;

/**
 * Mailchimp implementation of silvershop/newsletter's {@see NewsletterProvider}. A thin mapping layer: it turns a
 * {@see NewsletterSubscription} into a Mailchimp audience member (idempotent upsert + unsubscribe via
 * {@see MailchimpClient}) and maps Mailchimp webhooks back onto the local subscription status. Mirrors the
 * Campaign Monitor adapter's shape.
 *
 * Configuration comes from the environment (mirroring the Campaign Monitor adapter):
 *   SS_MAILCHIMP_API_KEY        the Mailchimp API key (also encodes the datacenter as its `-<dc>` suffix)
 *   SS_MAILCHIMP_LIST_ID        the target audience (list) id
 *   SS_MAILCHIMP_WEBHOOK_SECRET optional shared secret required on the webhook URL (?secret=…)
 *
 * The core already performs double opt-in, so the Mailchimp audience should be configured as **single** opt-in —
 * do not let Mailchimp send its own confirmation email on top of the core's. If you specifically want Mailchimp to
 * run the confirmed-opt-in step instead, set {@see $double_opt_in} true and the adapter sends `status: pending`.
 */
class MailchimpProvider implements NewsletterProvider
{
    use Configurable;
    use Injectable;

    /**
     * When true, new subscribers are sent to Mailchimp with `status: pending` so Mailchimp runs its own
     * confirmed-opt-in email. Off by default: the core owns double opt-in and the audience should be single
     * opt-in (`status: subscribed`), to avoid a second confirmation email.
     */
    private static bool $double_opt_in = false;

    /**
     * Map of NewsletterSubscription merge-field key => Mailchimp merge tag, layered on top of the native
     * FNAME/LNAME mapping. The mapped merge tags must already exist on the Mailchimp audience.
     *
     * @var array<string, string>
     */
    private static array $merge_field_map = [];

    private ?MailchimpClient $client = null;

    public function setClient(MailchimpClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getKey(): string
    {
        return 'mailchimp';
    }

    public function getName(): string
    {
        return _t(self::class . '.NAME', 'Mailchimp');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->listId() !== '';
    }

    public function isRemote(): bool
    {
        return true;
    }

    public function supportsDoubleOptIn(): bool
    {
        // The core owns double opt-in; the audience should be single opt-in unless $double_opt_in is set, in which
        // case Mailchimp runs the confirmed-opt-in step (status: pending).
        return (bool) static::config()->get('double_opt_in');
    }

    public function supportsEcommerce(): bool
    {
        return false;
    }

    public function supportsMergeFields(): bool
    {
        return true;
    }

    public function supportsTags(): bool
    {
        return true;
    }

    public function supportsSegments(): bool
    {
        return true;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function subscribe(NewsletterSubscription $subscription): bool
    {
        return $this->upsert($subscription);
    }

    public function update(NewsletterSubscription $subscription): bool
    {
        return $this->upsert($subscription);
    }

    public function unsubscribe(NewsletterSubscription $subscription): bool
    {
        try {
            $this->client()->unsubscribe((string) $subscription->Email);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function syncAll(): int
    {
        $synced = 0;
        $subscribers = NewsletterSubscription::get()->filter([
            'Status' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'Consent' => true,
        ]);
        foreach ($subscribers as $subscription) {
            if ($this->upsert($subscription)) {
                $synced++;
            }
        }

        return $synced;
    }

    public function verifyWebhook(HTTPRequest $request): bool
    {
        // Mailchimp does NOT HMAC-sign webhooks (unlike Campaign Monitor); it secures them with a secret embedded
        // in the webhook URL. So we validate a configurable ?secret=… query param, not a signature header.
        $secret = (string) Environment::getEnv('SS_MAILCHIMP_WEBHOOK_SECRET');
        if ($secret === '') {
            // No shared secret configured — accept (the webhook only ever changes status for known emails).
            return true;
        }

        return hash_equals($secret, (string) $request->getVar('secret'));
    }

    public function parseWebhook(HTTPRequest $request): array
    {
        // Mailchimp posts form-encoded, one event per request:
        //   type=subscribe|unsubscribe|cleaned|upemail|profile|campaign  &  data[email]=…
        // Only subscribe / unsubscribe / cleaned map to a local status; the rest are ignored gracefully.
        $data = $request->postVar('data');
        $email = is_array($data) ? (string) ($data['email'] ?? '') : '';
        $status = $this->mapEventType((string) $request->postVar('type'));

        if ($email === '' || $status === '') {
            return [];
        }

        return [['email' => $email, 'status' => $status]];
    }

    /**
     * Map a Mailchimp webhook event type to a local subscription status.
     */
    private function mapEventType(string $type): string
    {
        return match (strtolower($type)) {
            'subscribe' => NewsletterSubscription::STATUS_SUBSCRIBED,
            'unsubscribe' => NewsletterSubscription::STATUS_UNSUBSCRIBED,
            'cleaned' => NewsletterSubscription::STATUS_CLEANED,
            default => '',
        };
    }

    private function upsert(NewsletterSubscription $subscription): bool
    {
        try {
            $this->client()->upsertMember((string) $subscription->Email, $this->payload($subscription));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Build the Mailchimp upsert-member payload from a subscription. FNAME/LNAME are mapped natively; any extra
     * merge fields come from {@see $merge_field_map}.
     *
     * @return array<string, mixed>
     */
    public function payload(NewsletterSubscription $subscription): array
    {
        $merge = $subscription->getMergeFields();

        $mergeFields = [];
        if (!empty($merge['FirstName'])) {
            $mergeFields['FNAME'] = $merge['FirstName'];
        }
        if (!empty($merge['Surname'])) {
            $mergeFields['LNAME'] = $merge['Surname'];
        }
        foreach ((array) static::config()->get('merge_field_map') as $field => $tag) {
            if (!empty($merge[$field])) {
                $mergeFields[$tag] = $merge[$field];
            }
        }

        return [
            'email_address' => (string) $subscription->Email,
            'status' => $this->subscribeStatus(),
            'merge_fields' => $mergeFields,
        ];
    }

    /**
     * The Mailchimp status for an (re)subscribe: single opt-in `subscribed` by default, or `pending` when
     * {@see $double_opt_in} is set so Mailchimp runs its own confirmed-opt-in.
     */
    private function subscribeStatus(): string
    {
        return static::config()->get('double_opt_in') ? 'pending' : 'subscribed';
    }

    private function apiKey(): string
    {
        return (string) Environment::getEnv('SS_MAILCHIMP_API_KEY');
    }

    private function listId(): string
    {
        return (string) Environment::getEnv('SS_MAILCHIMP_LIST_ID');
    }

    private function client(): MailchimpClient
    {
        return $this->client ??= MailchimpClient::create($this->apiKey(), $this->listId());
    }
}
