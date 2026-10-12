<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Mailchimp;

use GuzzleHttp\Client;
use SilverStripe\Core\Injector\Injectable;

/**
 * Thin Mailchimp Marketing API v3 client (Guzzle). Deliberately minimal — just the member upsert + unsubscribe
 * endpoints the provider needs — to avoid the official mailchimp/marketing SDK (which carries its own HTTP stack).
 * Mirrors silvershop/newsletter-campaignmonitor's CampaignMonitorClient and silvershop/search-meilisearch's
 * MeiliClient.
 *
 * The API host is datacenter-scoped: `https://<dc>.api.mailchimp.com/3.0/`, where `<dc>` is the suffix of the API
 * key after the last `-` (e.g. key `abc123def-us21` → dc `us21`). Authentication is HTTP Basic with any non-empty
 * username and the API key as the password.
 * @see https://mailchimp.com/developer/marketing/api/
 */
class MailchimpClient
{
    use Injectable;

    private Client $http;

    public function __construct(string $apiKey, private string $listId)
    {
        $this->http = new Client([
            'base_uri' => sprintf('https://%s.api.mailchimp.com/3.0/', self::dataCenter($apiKey)),
            // Mailchimp HTTP Basic: username can be any non-empty string, password is the API key.
            'auth' => ['anystring', $apiKey],
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 15,
        ]);
    }

    /**
     * Derive the Mailchimp datacenter from the API key — the suffix after the last `-` (e.g. `abc-us21` → `us21`).
     * A key without a `-<datacenter>` suffix cannot be mapped to an API host, so this throws a clear config error.
     */
    public static function dataCenter(string $apiKey): string
    {
        $pos = strrpos($apiKey, '-');
        if ($pos === false || $pos === strlen($apiKey) - 1) {
            throw new \InvalidArgumentException(
                'Mailchimp API key is missing its "-<datacenter>" suffix (e.g. "…-us21"); cannot derive the API host.'
            );
        }

        return substr($apiKey, $pos + 1);
    }

    /**
     * Mailchimp's member id: the md5 hash of the lowercased email address.
     */
    public static function subscriberHash(string $email): string
    {
        return md5(strtolower($email));
    }

    /**
     * Add or update a member on the audience (idempotent upsert via PUT), so this one call covers both subscribe
     * and update. The member is addressed by the md5 subscriber hash of the email.
     *
     * @param array<string, mixed> $body e.g. ['email_address' => …, 'status' => 'subscribed', 'merge_fields' => …]
     * @return array<string, mixed>
     */
    public function upsertMember(string $email, array $body): array
    {
        return $this->json('PUT', "lists/{$this->listId}/members/" . self::subscriberHash($email), $body);
    }

    /**
     * Unsubscribe a member (PATCH the member's status to `unsubscribed`).
     */
    public function unsubscribe(string $email): void
    {
        $this->http->request('PATCH', "lists/{$this->listId}/members/" . self::subscriberHash($email), [
            'json' => ['status' => 'unsubscribed'],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function json(string $method, string $uri, array $body): array
    {
        $response = $this->http->request($method, $uri, ['json' => $body]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }
}
