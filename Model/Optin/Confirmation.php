<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Optin;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\NotificationTwilio\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The opt-in confirmation (Notification type "twilio_optin", reference = the subscription id): one text per number
 * and website, ever – the claim is one row in Notification's delivery state (type twilio_optin, reference = the
 * number's contact id), so a retry, a second sign-up or a second worker never sends it twice. Not stoppable, no
 * cooldown: the person just asked for it, and it must not hold back their first real message.
 */
class Confirmation implements NotificationTypeInterface
{
    public const TYPE = 'twilio_optin';

    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly DeliveryStateInterface $state,
        private readonly SenderInterface $sender,
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly ResourceConnection $resource
    ) {
    }

    public function process(string $reference): void
    {
        $subscription = $this->subscriptions->get((int)$reference);
        $recipient = $subscription !== null ? $this->subscriptions->getRecipient($subscription) : null;
        if ($subscription === null || $subscription->contactId === null || $recipient === null
            || !in_array($recipient['channel'], [Channel::SMS, Channel::WHATSAPP], true)) {
            return; // gone (unsubscribed or stopped) or not a phone channel
        }
        $contactId = $subscription->contactId;
        $this->state->ensure(self::TYPE, $contactId);
        $db = $this->resource->getConnection();
        $claimed = $db->update($this->state->getTableName(),
            ['sent_at' => new Expression('NOW()'), 'delivery_version' => new Expression('delivery_version + 1')],
            array_merge($this->state->keyCondition(self::TYPE, $contactId), ['sent_at IS NULL']));
        if (!$claimed) {
            return; // already confirmed (or another worker is sending it)
        }
        $storeId = $subscription->storeId;
        $text = strtr($this->config->getOptinText($storeId), ['{{store}}' => (string)$this->stores->getStore($storeId)->getName()]);
        try {
            $this->sender->send(Delivery::text($storeId, $recipient['channel'], $recipient['recipient'],
                fn () => new DeliveryContent(['store' => (string)$this->stores->getStore($storeId)->getName()], text: $text),
                false, null, false, self::TYPE));
        } catch (MessageSentException $sent) {
            throw $sent; // it is out: keep the claim
        } catch (\Throwable $error) {
            // not sent: release the claim, so a later sign-up of the number may confirm it
            $db->update($this->state->getTableName(), ['sent_at' => null], $this->state->keyCondition(self::TYPE, $contactId));
            throw $error;
        }
    }
}
