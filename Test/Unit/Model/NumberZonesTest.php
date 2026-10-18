<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model;

use CommerceKings\NotificationTwilio\Model\NumberZones;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

/** The recipient's time zones from the number (libphonenumber), for quiet hours in their local time. */
class NumberZonesTest extends TestCase
{
    private function names(?string $number): array
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/Berlin');
        return array_map(fn (\DateTimeZone $zone) => $zone->getName(),
            (new NumberZones($timezone))->getZones(1, 'sms', $number));
    }

    public function testANumberGivesItsZones(): void
    {
        $this->assertSame(['America/New_York'], $this->names('+12125550100'), 'a New York area code');
        $this->assertSame(['Europe/Belgrade'], $this->names('+381601234567'));
        $this->assertContains('America/Los_Angeles', $this->names('+14155550100'));
    }

    /** A number it cannot place keeps the store's zone: quiet hours still apply (never "not quiet"). */
    public function testANumberItCannotPlaceKeepsTheStoresZone(): void
    {
        $this->assertSame(['Europe/Berlin'], $this->names('not a number'));
        $this->assertSame(['Europe/Berlin'], $this->names('+999123'), 'no country');
    }

    public function testTheWholeStoreGetsNone(): void
    {
        $this->assertSame([], $this->names(null), 'the whole store: each delivery is held on its own');
    }
}
