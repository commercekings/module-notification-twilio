<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Cron;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\NotificationTwilio\Cron\CleanUpOptinState;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class CleanUpOptinStateTest extends TestCase
{
    public function testOnlyRowsOfNumbersNotificationNoLongerKeepsAreRemoved(): void
    {
        $deleted = [];
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturn(['3', '5', '8']);
        $db->method('delete')->willReturnCallback(function ($table, $where) use (&$deleted) {
            $deleted[] = $where;
            return count($where['reference_id IN (?)']);
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $contacts = $this->createStub(ContactRepositoryInterface::class);
        $contacts->method('getRecipient')->willReturnCallback(fn ($id) => $id === 5 ? ['channel' => 'sms', 'recipient' => '+1'] : null);
        $this->assertSame(2, (new CleanUpOptinState($resource, $state, $contacts))->execute());
        $this->assertSame(['type = ?' => 'twilio_optin', 'reference_id IN (?)' => [3, 8]], $deleted[0]);
    }
}
