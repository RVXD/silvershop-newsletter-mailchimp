<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Mailchimp\Tests;

use SilverShop\Newsletter\Mailchimp\MailchimpClient;

/**
 * In-memory stand-in for {@link MailchimpClient} — records calls, returns canned responses, makes no HTTP request.
 * Deliberately does not call the parent constructor (so no datacenter / Guzzle setup). The static
 * {@see MailchimpClient::dataCenter()} / {@see MailchimpClient::subscriberHash()} helpers are pure and tested on the
 * real class. Mirrors the Campaign Monitor adapter's FakeCampaignMonitorClient.
 */
class FakeMailchimpClient extends MailchimpClient
{
    /** @var array<int, array{email: string, body: array<string, mixed>}> */
    public array $upserted = [];

    /** @var array<int, string> */
    public array $unsubscribed = [];

    public bool $throwOnUpsert = false;

    public function __construct()
    {
        // No HTTP client.
    }

    public function upsertMember(string $email, array $body): array
    {
        if ($this->throwOnUpsert) {
            throw new \RuntimeException('Simulated Mailchimp API error');
        }
        $this->upserted[] = ['email' => $email, 'body' => $body];

        return ['email_address' => $email, 'status' => $body['status'] ?? ''];
    }

    public function unsubscribe(string $email): void
    {
        $this->unsubscribed[] = $email;
    }
}
