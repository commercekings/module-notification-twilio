<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Provider;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\NotificationTwilio\Model\MessageSender;

/** Twilio WhatsApp: Notification's provider for Channel::WHATSAPP. */
class WhatsappProvider implements ProviderInterface
{
    public const CODE = 'twilio_whatsapp';

    public function __construct(private readonly MessageSender $sender)
    {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getLabel(): string
    {
        return 'Twilio WhatsApp';
    }

    public function getChannel(): string
    {
        return Channel::WHATSAPP;
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
