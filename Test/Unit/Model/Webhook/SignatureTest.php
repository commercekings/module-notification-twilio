<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model\Webhook;

use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\Webhook\Signature;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    private const PARAMS = ['AccountSid' => 'AC1', 'From' => '+381601234567', 'Body' => 'STOP', 'To' => '+14155550100'];

    private function signature(): Signature
    {
        $config = $this->createStub(Config::class);
        $config->method('getStoresOfAccount')->willReturnCallback(fn ($sid) => $sid === 'AC1' ? [1, 2] : []);
        $config->method('getAuthToken')->willReturnCallback(fn ($store) => $store === 2 ? 'token-two' : 'token-one');
        $config->method('getWebhookBaseUrl')->willReturnCallback(fn ($store) => $store === 2 ? 'https://two.test' : 'https://one.test');
        return new Signature($config);
    }

    /** Twilio's algorithm, written out: URL, then name and value of each parameter sorted by name, HMAC-SHA1. */
    private function expected(string $url, string $token): string
    {
        $data = $url . 'AccountSidAC1BodySTOPFrom+381601234567To+14155550100';
        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    public function testASignatureMatchesTheConfiguredUrlOfAWebsiteOfTheAccount(): void
    {
        $signature = $this->signature();
        $this->assertTrue($signature->isValid('/cktwilio/webhook/inbound', self::PARAMS,
            $this->expected('https://two.test/cktwilio/webhook/inbound', 'token-two')), 'the second website of the account');
        $this->assertFalse($signature->isValid('/cktwilio/webhook/inbound', self::PARAMS,
            $this->expected('https://proxy.internal/cktwilio/webhook/inbound', 'token-one')), 'not the request\'s own URL');
        $this->assertFalse($signature->isValid('/cktwilio/webhook/inbound', ['Body' => 'START'] + self::PARAMS,
            $this->expected('https://one.test/cktwilio/webhook/inbound', 'token-one')), 'altered parameters');
        $this->assertFalse($signature->isValid('/cktwilio/webhook/inbound', ['AccountSid' => 'AC9'] + self::PARAMS,
            $this->expected('https://one.test/cktwilio/webhook/inbound', 'token-one')), 'another account');
        $this->assertFalse($signature->isValid('/cktwilio/webhook/inbound', self::PARAMS, ''));
    }
}
