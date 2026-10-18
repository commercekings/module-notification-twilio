<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Observer;

use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Observer\QueueOptinConfirmation;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;

/** A number's first subscription in the website queues one confirmation, when Twilio sends that channel. */
class QueueOptinConfirmationTest extends TestCase
{
    private array $published = [];
    private bool $enabled = true;
    private string $provider = 'twilio_sms';

    private function dispatch(array $data): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOptinEnabled')->willReturnCallback(fn () => $this->enabled);
        $selected = $this->createStub(ProviderInterface::class);
        $selected->method('getCode')->willReturnCallback(fn () => $this->provider);
        $providers = $this->createStub(ProviderPoolInterface::class);
        $providers->method('getSelected')->willReturn($selected);
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($type, $reference) { $this->published[] = [$type, $reference]; });
        (new QueueOptinConfirmation($config, $providers, $queue))->execute(new Observer($data + [
            'subscription_id' => 7, 'channel' => 'sms', 'store_id' => 1, 'contact_id' => 5, 'first_for_address' => true]));
    }

    public function testTheFirstSubscriptionOfANumberIsConfirmed(): void
    {
        $this->dispatch([]);
        $this->assertSame([['twilio_optin', '7']], $this->published);
    }

    public function testNothingForAKnownNumberEmailAnotherProviderOrWhenOff(): void
    {
        $this->dispatch(['first_for_address' => false]);
        $this->dispatch(['channel' => 'email']);
        $this->provider = 'infobip_sms';
        $this->dispatch([]);
        $this->provider = 'twilio_sms';
        $this->enabled = false;
        $this->dispatch([]);
        $this->assertSame([], $this->published);
    }
}
