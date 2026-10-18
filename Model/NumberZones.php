<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model;

use CommerceKings\Notification\Api\QuietHoursZoneInterface;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberToTimeZonesMapper;
use libphonenumber\PhoneNumberUtil;

/**
 * Quiet hours in the recipient's time zone (registered for sms and whatsapp in Model\QuietHours "zones"): the zones
 * libphonenumber knows for the number's country and area code – several for a number whose area spans zones, and
 * Notification keeps it quiet while any of them is in quiet hours. [] for a number it cannot place and for the whole
 * store (null): each delivery is then held on its own.
 */
class NumberZones implements QuietHoursZoneInterface
{
    public function getZones(int $storeId, string $channel, ?string $recipient): array
    {
        if ($recipient === null || $recipient === '') {
            return [];
        }
        try {
            $number = PhoneNumberUtil::getInstance()->parse($recipient, null);
        } catch (NumberParseException) {
            return [];
        }
        $zones = [];
        foreach (PhoneNumberToTimeZonesMapper::getInstance()->getTimeZonesForNumber($number) as $name) {
            if ($name === PhoneNumberToTimeZonesMapper::UNKNOWN_TIMEZONE) {
                continue;
            }
            try {
                $zones[] = new \DateTimeZone($name);
            } catch (\Exception) {
                continue; // a zone this PHP does not know
            }
        }
        return $zones;
    }
}
