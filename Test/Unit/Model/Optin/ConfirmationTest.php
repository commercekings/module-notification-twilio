<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model\Optin;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\Optin\Confirmation;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** One confirmation per number and website, ever: claimed in Notification's delivery state before it is sent. */
class ConfirmationTest extends TestCase
{
    private array $updates = [];
    private int $claimResult = 1;
    private array $deliveries = [];
    private ?\Throwable $sendFailure = null;
    private ?Subscription $subscription;

    protected function setUp(): void
    {
        $this->subscription = new Subscription(7, 'cart_reminder_sms', 42, 1, 5, null, 'ac:x', '2026-10-18 10:00:00');
    }

    private function confirmation(): Confirmation
    {
        $repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(fn () => $this->subscription);
        $repository->method('getRecipient')->willReturn(['channel' => 'sms', 'recipient' => '+381601234567']);
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $state->method('keyCondition')->willReturnCallback(fn ($type, $id) => ['type = ?' => $type, 'reference_id = ?' => $id, "subject_key = ''"]);
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) {
            $this->deliveries[] = $delivery;
            if ($this->sendFailure) {
                throw $this->sendFailure;
            }
            return SenderInterface::SENT;
        });
        $config = $this->createStub(Config::class);
        $config->method('getOptinText')->willReturn('{{store}}: you are signed up. Reply STOP to stop.');
        $store = $this->createStub(StoreInterface::class);
        $store->method('getName')->willReturn('Acme');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        $db = $this->createStub(AdapterInterface::class);
        $db->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$data, $where];
            return count($this->updates) === 1 ? $this->claimResult : 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        return new Confirmation($repository, $state, $sender, $config, $stores, $resource);
    }

    public function testTheClaimedNumberGetsOneTextThatCannotBeStoppedOrHeld(): void
    {
        $this->confirmation()->process('7');
        $this->assertContains('sent_at IS NULL', $this->updates[0][1], 'claimed only if never sent');
        $this->assertSame(5, $this->updates[0][1]['reference_id = ?'], 'keyed by the number contact');
        $delivery = $this->deliveries[0];
        $this->assertSame(['sms', '+381601234567', false, false, 'twilio_optin'],
            [$delivery->channel, $delivery->recipient, $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $this->assertSame('Acme: you are signed up. Reply STOP to stop.', ($delivery->content)()->text);
    }

    public function testAlreadyConfirmedOrGoneSendsNothing(): void
    {
        $this->claimResult = 0;
        $this->confirmation()->process('7');
        $this->subscription = null;
        $this->confirmation()->process('7');
        $this->assertSame([], $this->deliveries);
    }

    public function testAFailedSendReleasesTheClaimButASentOneKeepsIt(): void
    {
        $this->sendFailure = new \RuntimeException('Twilio unreachable');
        try {
            $this->confirmation()->process('7');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['sent_at' => null], $this->updates[1][0], 'released');
        $this->updates = [];
        $this->sendFailure = new MessageSentException('no answer');
        try {
            $this->confirmation()->process('7');
        } catch (MessageSentException) {
        }
        $this->assertCount(1, $this->updates, 'kept: it may be out');
    }
}
