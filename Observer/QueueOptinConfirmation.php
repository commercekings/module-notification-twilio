<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Observer;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\Optin\Confirmation;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * commercekings_notification_subscription_saved: the first subscription of a number in the website
 * (first_for_address) gets one opt-in confirmation – queued, never sent from the shopper's request – when Twilio is the
 * channel's provider and the store has it on. A new cart of a known number gets none.
 */
class QueueOptinConfirmation implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPoolInterface $providers,
        private readonly QueueInterface $queue
    ) {
    }

    public function execute(Observer $observer): void
    {
        $channel = (string)$observer->getData('channel');
        $storeId = (int)$observer->getData('store_id');
        if (!in_array($channel, [Channel::SMS, Channel::WHATSAPP], true) || !$observer->getData('first_for_address')
            || !$observer->getData('contact_id') || !$this->config->isOptinEnabled($storeId)) {
            return;
        }
        try {
            $provider = $this->providers->getSelected($storeId, $channel)->getCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            return;
        }
        if (str_starts_with($provider, 'twilio_')) {
            $this->queue->publish(Confirmation::TYPE, (string)(int)$observer->getData('subscription_id'));
        }
    }
}
