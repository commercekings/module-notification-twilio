<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model;

use CommerceKings\NotificationTwilio\Model\NumberZones;
use PHPUnit\Framework\TestCase;

/** The recipient's time zones from the number (libphonenumber), for quiet hours in their local time. */
class NumberZonesTest extends TestCase
{
    private function names(?string $number): array
    {
        return array_map(fn (\DateTimeZone $zone) => $zone->getName(), (new NumberZones())->getZones(1, 'sms', $number));
    }

    public function testANumberGivesItsZones(): void
    {
        $this->assertSame(['America/New_York'], $this->names('+12125550100'), 'a New York area code');
        $this->assertSame(['Europe/Belgrade'], $this->names('+381601234567'));
        $this->assertContains('America/Los_Angeles', $this->names('+14155550100'));
    }

    public function testUnknownOrTheWholeStoreGiveNone(): void
    {
        $this->assertSame([], $this->names(null), 'the whole store: each delivery is held on its own');
        $this->assertSame([], $this->names('not a number'));
    }
}
