<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/** Admin: Stores > Configuration > CommerceKings > Notifications > Twilio (credentials per website). */
class Config
{
    private const PREFIX = 'commercekings_notification/twilio/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function getAccountSid(int $storeId): string
    {
        return $this->value('account_sid', $storeId);
    }

    /** Stored encrypted; Magento decrypts it on read (config.xml marks it with the Encrypted backend model). */
    public function getAuthToken(int $storeId): string
    {
        return $this->value('auth_token', $storeId);
    }

    /** A Messaging Service SID (MG…): Twilio picks the sender and handles opt-out keywords; preferred over a number. */
    public function getMessagingServiceSid(int $storeId): string
    {
        return $this->value('messaging_service_sid', $storeId);
    }

    public function getSmsFrom(int $storeId): string
    {
        return $this->value('sms_from', $storeId);
    }

    public function getWhatsappFrom(int $storeId): string
    {
        return $this->value('whatsapp_from', $storeId);
    }

    /**
     * The public URL Twilio calls the webhooks on, without a trailing slash: the setting, else the store's secure base
     * URL. Twilio signs the URL it called; behind a proxy the request's own URL differs, so it is never used.
     */
    public function getWebhookBaseUrl(int $storeId): string
    {
        $configured = rtrim($this->value('webhook_base_url', $storeId), '/');
        if ($configured !== '') {
            return $configured;
        }
        return rtrim((string)$this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE, $storeId), '/');
    }

    public function isOptinEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'optin_enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getOptinText(int $storeId): string
    {
        return $this->value('optin_text', $storeId);
    }

    /** @return array<int, array<string, mixed>> rows of type, locale, content_sid and variables */
    public function getTemplateMap(int $storeId): array
    {
        $raw = $this->value('template_map', $storeId);
        if ($raw === '') {
            return [];
        }
        try {
            $rows = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * The store views of the websites whose account SID is this one (a webhook names the account, not the store).
     *
     * @return int[] store ids, one per website: its default store view
     */
    public function getStoresOfAccount(string $accountSid): array
    {
        $stores = [];
        foreach ($this->stores->getWebsites() as $website) {
            $storeId = (int)$this->stores->getGroup((string)$website->getDefaultGroupId())->getDefaultStoreId();
            if ($storeId && $accountSid !== '' && hash_equals($this->getAccountSid($storeId), $accountSid)) {
                $stores[] = $storeId;
            }
        }
        return $stores;
    }

    /**
     * The brand in texts ({{store}}), the same rule as the other CommerceKings texts: the store view's Store Information
     * name, else Magento's frontend name (the store's name).
     */
    public function getStoreName(int $storeId): string
    {
        $name = trim((string)$this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE,
            $storeId));
        if ($name !== '') {
            return $name;
        }
        $store = $this->stores->getStore($storeId);
        return (string)($store instanceof Store ? $store->getFrontendName() : $store->getName());
    }

    private function value(string $field, int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
