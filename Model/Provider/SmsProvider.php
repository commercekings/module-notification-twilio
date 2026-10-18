<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Provider;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\NotificationTwilio\Model\MessageSender;

/** Twilio SMS: Notification's provider for Channel::SMS. */
class SmsProvider implements ProviderInterface
{
    public const CODE = 'twilio_sms';

    public function __construct(private readonly MessageSender $sender)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Twilio SMS';
    }

    public function getChannel(): string
    {
        return Channel::SMS;
    }

    public function supportsOneClickUnsubscribe(): bool
    {
        return false;
    }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        $this->sender->send($delivery, $content);
    }
}
