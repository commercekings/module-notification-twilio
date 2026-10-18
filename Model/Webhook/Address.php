<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Webhook;

use CommerceKings\Notification\Api\Channel;

/** A Twilio address ("+3816…" or "whatsapp:+3816…") as Notification's channel and number. */
class Address
{
    /** @return array{channel: string, recipient: string} */
    public static function parse(string $address): array
    {
        $address = trim($address);
        $whatsapp = stripos($address, 'whatsapp:') === 0;
        $number = $whatsapp ? substr($address, 9) : $address;
        // Twilio sends E.164 with "+"; a number without it would be refused by Notification's normalizer
        if ($number !== '' && ctype_digit($number)) {
            $number = '+' . $number;
        }
        return ['channel' => $whatsapp ? Channel::WHATSAPP : Channel::SMS, 'recipient' => $number];
    }
}
