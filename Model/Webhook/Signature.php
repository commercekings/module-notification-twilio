<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Webhook;

use CommerceKings\NotificationTwilio\Model\Config;

/**
 * Twilio's request signature (X-Twilio-Signature): base64 HMAC-SHA1 of the URL it called followed by every POST
 * parameter name and value, sorted by name, keyed with the account's auth token. Checked against the configured
 * public URL of each website whose account sent it (a webhook names the account, not the store).
 */
class Signature
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param string $path the webhook path, e.g. "/cktwilio/webhook/inbound"
     * @param array<string, mixed> $params the POST parameters as received
     */
    public function isValid(string $path, array $params, string $signature): bool
    {
        $accountSid = is_string($params['AccountSid'] ?? null) ? $params['AccountSid'] : '';
        if ($signature === '' || $accountSid === '') {
            return false;
        }
        foreach ($this->config->getStoresOfAccount($accountSid) as $storeId) {
            $token = $this->config->getAuthToken($storeId);
            $url = $this->config->getWebhookBaseUrl($storeId) . $path;
            if ($token !== '' && hash_equals($this->compute($url, $params, $token), $signature)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $params */
    public function compute(string $url, array $params, string $token): string
    {
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $name => $value) {
            $values = is_array($value) ? $value : [$value];
            sort($values, SORT_STRING);
            foreach ($values as $item) {
                $data .= $name . (is_scalar($item) ? (string)$item : '');
            }
        }
        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }
}
