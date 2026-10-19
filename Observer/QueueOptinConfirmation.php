<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Observer;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\Optin\Confirmation;
use CommerceKings\NotificationTwilio\Model\Optin\Pending;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * commercekings_notification_subscription_saved: the first subscription of a number in the website
 * (first_for_address) gets one opt-in confirmation – queued, never sent from the shopper's request – when Twilio is the
 * channel's provider and the store has it on. A new cart of a known number gets none. It is recorded as pending first
 * (Pending), so a broker that is down does not lose it: the retry cron publishes it again.
 */
class QueueOptinConfirmation implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPoolInterface $providers,
        private readonly QueueInterface $queue,
        private readonly Pending $pending,
        private readonly LoggerInterface $logger
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
        if (!str_starts_with($provider, 'twilio_')) {
            return;
        }
        $subscriptionId = (int)$observer->getData('subscription_id');
        $this->pending->queued($subscriptionId);
        try {
            $this->queue->publish(Confirmation::TYPE, (string)$subscriptionId);
        } catch (\Throwable $error) {
            // the sign-up stands; the retry cron publishes the confirmation again
            $this->pending->notQueued($subscriptionId);
            $this->logger->warning(sprintf('CommerceKings_NotificationTwilio: the opt-in confirmation of subscription %d'
                . ' could not be queued (%s); it is retried.', $subscriptionId, get_class($error)));
        }
    }
}
