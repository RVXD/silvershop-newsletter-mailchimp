<?php

declare(strict_types=1);

namespace SilverShop\Newsletter\Mailchimp\Tests;

use SilverShop\Newsletter\Mailchimp\MailchimpClient;
use SilverShop\Newsletter\Mailchimp\MailchimpProvider;
use SilverShop\Newsletter\Model\NewsletterSubscription;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

class MailchimpProviderTest extends SapphireTest
{
    protected $usesDatabase = true;

    public function testCapabilityFlags(): void
    {
        $provider = new MailchimpProvider();
        $this->assertSame('mailchimp', $provider->getKey());
        $this->assertTrue($provider->isRemote());
        $this->assertTrue($provider->supportsWebhooks());
        $this->assertTrue($provider->supportsMergeFields());
        // The core owns double opt-in by default, so the Mailchimp audience is single opt-in.
        $this->assertFalse($provider->supportsDoubleOptIn());
    }

    public function testIsConfiguredFromEnv(): void
    {
        Environment::setEnv('SS_MAILCHIMP_API_KEY', '');
        Environment::setEnv('SS_MAILCHIMP_LIST_ID', '');
        $this->assertFalse((new MailchimpProvider())->isConfigured());

        Environment::setEnv('SS_MAILCHIMP_API_KEY', 'key-us21');
        Environment::setEnv('SS_MAILCHIMP_LIST_ID', 'list-abc');
        $this->assertTrue((new MailchimpProvider())->isConfigured());
    }

    public function testDataCenterDerivation(): void
    {
        $this->assertSame('us21', MailchimpClient::dataCenter('abc123def-us21'));
        $this->assertSame('us1', MailchimpClient::dataCenter('0a1b2c3d-us1'));
        // Only the suffix after the LAST dash counts.
        $this->assertSame('eu2', MailchimpClient::dataCenter('abc-def-eu2'));
    }

    public function testDataCenterThrowsWithoutSuffix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailchimpClient::dataCenter('no-datacenter-suffix-here-');
    }

    public function testDataCenterThrowsWithoutDash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailchimpClient::dataCenter('plainkeynodash');
    }

    public function testSubscriberHash(): void
    {
        // md5 of the LOWERCASED email, regardless of input case.
        $this->assertSame(md5('buyer@example.com'), MailchimpClient::subscriberHash('Buyer@Example.com'));
        $this->assertSame(
            MailchimpClient::subscriberHash('buyer@example.com'),
            MailchimpClient::subscriberHash('BUYER@EXAMPLE.COM')
        );
    }

    public function testSubscribeSendsExpectedPayload(): void
    {
        $fake = new FakeMailchimpClient();
        $provider = (new MailchimpProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'buyer@example.com';
        $subscription->FirstName = 'Ada';
        $subscription->Surname = 'Lovelace';
        $subscription->confirm();
        $subscription->write();

        $this->assertTrue($provider->subscribe($subscription));
        $this->assertCount(1, $fake->upserted);
        $this->assertSame('buyer@example.com', $fake->upserted[0]['email']);
        $body = $fake->upserted[0]['body'];
        $this->assertSame('buyer@example.com', $body['email_address']);
        // Single opt-in by default.
        $this->assertSame('subscribed', $body['status']);
        $this->assertSame('Ada', $body['merge_fields']['FNAME']);
        $this->assertSame('Lovelace', $body['merge_fields']['LNAME']);
    }

    public function testDoubleOptInSendsPendingStatus(): void
    {
        MailchimpProvider::config()->set('double_opt_in', true);
        $fake = new FakeMailchimpClient();
        $provider = (new MailchimpProvider())->setClient($fake);
        $this->assertTrue($provider->supportsDoubleOptIn());

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'doi@example.com';
        $subscription->confirm();
        $subscription->write();

        $provider->subscribe($subscription);
        $this->assertSame('pending', $fake->upserted[0]['body']['status']);

        MailchimpProvider::config()->set('double_opt_in', false);
    }

    public function testMergeFieldMapping(): void
    {
        MailchimpProvider::config()->set('merge_field_map', ['Locale' => 'MMERGE3']);
        $fake = new FakeMailchimpClient();
        $provider = (new MailchimpProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'loc@example.com';
        $subscription->Locale = 'nl_NL';
        $subscription->confirm();
        $subscription->write();

        $provider->subscribe($subscription);
        $this->assertSame('nl_NL', $fake->upserted[0]['body']['merge_fields']['MMERGE3']);

        MailchimpProvider::config()->set('merge_field_map', []);
    }

    public function testSubscribeReturnsFalseOnApiError(): void
    {
        $fake = new FakeMailchimpClient();
        $fake->throwOnUpsert = true;
        $provider = (new MailchimpProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'err@example.com';
        $subscription->confirm();
        $subscription->write();

        $this->assertFalse($provider->subscribe($subscription));
    }

    public function testSubscribeReturnsFalseWhenKeyHasNoDatacenter(): void
    {
        // No fake client, so the provider builds a real MailchimpClient — whose constructor rejects a key with no
        // "-<dc>" suffix. The provider swallows it and reports failure rather than fataling.
        Environment::setEnv('SS_MAILCHIMP_API_KEY', 'plainkeynodash');
        Environment::setEnv('SS_MAILCHIMP_LIST_ID', 'list-abc');

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'nodc@example.com';
        $subscription->confirm();
        $subscription->write();

        $this->assertFalse((new MailchimpProvider())->subscribe($subscription));

        Environment::setEnv('SS_MAILCHIMP_API_KEY', '');
        Environment::setEnv('SS_MAILCHIMP_LIST_ID', '');
    }

    public function testUnsubscribe(): void
    {
        $fake = new FakeMailchimpClient();
        $provider = (new MailchimpProvider())->setClient($fake);

        $subscription = NewsletterSubscription::create();
        $subscription->Email = 'leave@example.com';
        $subscription->write();

        $this->assertTrue($provider->unsubscribe($subscription));
        $this->assertSame(['leave@example.com'], $fake->unsubscribed);
    }

    public function testSyncAllPushesSubscribedRecords(): void
    {
        $fake = new FakeMailchimpClient();
        $provider = (new MailchimpProvider())->setClient($fake);

        foreach (['one@example.com', 'two@example.com'] as $email) {
            $subscription = NewsletterSubscription::create();
            $subscription->Email = $email;
            $subscription->confirm();
            $subscription->write();
        }
        // A pending record must NOT be synced.
        $pending = NewsletterSubscription::create();
        $pending->Email = 'pending@example.com';
        $pending->Status = NewsletterSubscription::STATUS_PENDING;
        $pending->write();

        $this->assertSame(2, $provider->syncAll());
        $this->assertCount(2, $fake->upserted);
    }

    public function testParseWebhookMapsSubscribe(): void
    {
        $provider = new MailchimpProvider();
        $request = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', [], [
            'type' => 'subscribe',
            'data' => ['email' => 'sub@example.com'],
        ]);

        $events = $provider->parseWebhook($request);
        $this->assertSame(
            [['email' => 'sub@example.com', 'status' => NewsletterSubscription::STATUS_SUBSCRIBED]],
            $events
        );
    }

    public function testParseWebhookMapsUnsubscribeAndCleaned(): void
    {
        $provider = new MailchimpProvider();

        $unsub = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', [], [
            'type' => 'unsubscribe',
            'data' => ['email' => 'off@example.com'],
        ]);
        $this->assertSame(
            [['email' => 'off@example.com', 'status' => NewsletterSubscription::STATUS_UNSUBSCRIBED]],
            $provider->parseWebhook($unsub)
        );

        $cleaned = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', [], [
            'type' => 'cleaned',
            'data' => ['email' => 'gone@example.com'],
        ]);
        $this->assertSame(
            [['email' => 'gone@example.com', 'status' => NewsletterSubscription::STATUS_CLEANED]],
            $provider->parseWebhook($cleaned)
        );
    }

    public function testParseWebhookIgnoresUnmappedTypeAndMissingEmail(): void
    {
        $provider = new MailchimpProvider();

        // Unmapped event type (profile/upemail/campaign) → nothing to apply.
        $profile = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', [], [
            'type' => 'profile',
            'data' => ['email' => 'edit@example.com'],
        ]);
        $this->assertSame([], $provider->parseWebhook($profile));

        // Mapped type but no email → nothing to apply.
        $noEmail = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', [], ['type' => 'unsubscribe']);
        $this->assertSame([], $provider->parseWebhook($noEmail));
    }

    public function testVerifyWebhookWithoutSecretAccepts(): void
    {
        Environment::setEnv('SS_MAILCHIMP_WEBHOOK_SECRET', '');
        $request = new HTTPRequest('POST', 'newsletter/webhook/mailchimp');
        $this->assertTrue((new MailchimpProvider())->verifyWebhook($request));
    }

    public function testVerifyWebhookWithSecret(): void
    {
        Environment::setEnv('SS_MAILCHIMP_WEBHOOK_SECRET', 's3cret');

        // Mailchimp carries the secret in the URL (query param), not an HMAC header.
        $ok = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', ['secret' => 's3cret']);
        $this->assertTrue((new MailchimpProvider())->verifyWebhook($ok));

        $bad = new HTTPRequest('POST', 'newsletter/webhook/mailchimp', ['secret' => 'wrong']);
        $this->assertFalse((new MailchimpProvider())->verifyWebhook($bad));

        Environment::setEnv('SS_MAILCHIMP_WEBHOOK_SECRET', '');
    }
}
