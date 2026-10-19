<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationTwilio\Exception\TwilioException;
use CommerceKings\NotificationTwilio\Model\Http\TwilioClient;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * One Twilio message for a delivery: an SMS with the text, or a WhatsApp template (a Content SID from the template map
 * or Delivery::$templateId) with its variables. Each message asks for the status webhook, which reports late failures.
 * Twilio refusing a number that replied STOP (21610) is that person's opt-out; refusing an invalid or non-mobile number
 * (21211, 21614) suppresses it (StopListInterface::REASON_UNDELIVERABLE). The stop list learns it, then the send fails
 * as refused.
 */
class MessageSender
{
    /** Twilio: the recipient unsubscribed from this sender (replied STOP). */
    public const ERROR_UNSUBSCRIBED = 21610;
    /** Twilio: the To number is invalid (21211) or not a mobile number (21614). */
    public const ERRORS_UNDELIVERABLE = [21211, 21614];

    public function __construct(
        private readonly Config $config,
        private readonly TwilioClient $client,
        private readonly TemplateMap $templates,
        private readonly StopListInterface $stopList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        $storeId = $delivery->storeId;
        $whatsapp = $delivery->channel === Channel::WHATSAPP;
        $params = ['To' => ($whatsapp ? 'whatsapp:' : '') . $delivery->recipient] + $this->sender($storeId, $whatsapp)
            + ($whatsapp ? $this->template($delivery, $content) : $this->text($content));
        $callback = $this->config->getWebhookBaseUrl($storeId);
        if ($callback !== '') {
            $params['StatusCallback'] = $callback . '/cktwilio/webhook/status';
        }
        try {
            $this->client->createMessage($this->config->getAccountSid($storeId), $this->config->getAuthToken($storeId), $params);
        } catch (TwilioException $error) {
            $reason = match (true) {
                $error->getTwilioCode() === self::ERROR_UNSUBSCRIBED => StopListInterface::REASON_REPLY,
                in_array($error->getTwilioCode(), self::ERRORS_UNDELIVERABLE, true) => StopListInterface::REASON_UNDELIVERABLE,
                default => null,
            };
            if ($reason !== null) {
                $this->stopList->stop($delivery->channel, $delivery->recipient, $reason);
            }
            throw $error;
        }
    }

    /** @return array<string, string> */
    private function sender(int $storeId, bool $whatsapp): array
    {
        // SMS: the Messaging Service when set (Twilio picks the number and handles opt-out keywords), else the number;
        // WhatsApp: its own sender number when set, else the Messaging Service (one with a WhatsApp sender)
        $service = $this->config->getMessagingServiceSid($storeId);
        $from = $whatsapp ? $this->config->getWhatsappFrom($storeId) : $this->config->getSmsFrom($storeId);
        if ($service !== '' && ($from === '' || !$whatsapp)) {
            return ['MessagingServiceSid' => $service];
        }
        if ($from !== '') {
            return ['From' => ($whatsapp ? 'whatsapp:' : '') . $from];
        }
        throw new \RuntimeException(sprintf('Twilio has no %s sender for this store: set a number or a Messaging Service.',
            $whatsapp ? 'WhatsApp' : 'SMS'));
    }

    /** @return array<string, string> */
    private function text(DeliveryContent $content): array
    {
        $text = trim((string)$content->text);
        if ($text === '') {
            throw new \RuntimeException('An SMS needs a text (DeliveryContent::$text).');
        }
        return ['Body' => $text];
    }

    /**
     * WhatsApp outside a conversation takes approved templates only: the type's template for the store's locale (read
     * inside the store's environment), else Delivery::$templateId.
     *
     * @return array<string, string>
     */
    private function template(Delivery $delivery, DeliveryContent $content): array
    {
        $locale = (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $delivery->storeId);
        $template = $this->templates->find($delivery->storeId, $delivery->type, $locale)
            ?? ($delivery->templateId !== '' ? ['content_sid' => $delivery->templateId, 'variables' => []] : null);
        if ($template === null) {
            throw new \RuntimeException(sprintf('No WhatsApp template for "%s" (%s): add one under Twilio > WhatsApp templates.',
                $delivery->type, $locale));
        }
        $params = ['ContentSid' => $template['content_sid']];
        $variables = [];
        foreach ($template['variables'] as $index => $name) {
            $value = $content->vars[$name] ?? '';
            $variables[(string)($index + 1)] = is_scalar($value) ? (string)$value : '';
        }
        if ($variables) {
            $params['ContentVariables'] = (string)json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $params;
    }
}
