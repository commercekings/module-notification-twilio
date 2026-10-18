<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Integration;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\QuietHours;
use CommerceKings\Notification\Model\Sender;
use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\Http\TwilioClient;
use CommerceKings\NotificationTwilio\Model\MessageSender;
use CommerceKings\NotificationTwilio\Model\NumberZones;
use CommerceKings\NotificationTwilio\Model\Optin\Confirmation;
use CommerceKings\NotificationTwilio\Model\Provider\SmsProvider;
use CommerceKings\NotificationTwilio\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationTwilio\Model\Webhook\StatusProcessor;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Real Magento and database, a stub in place of Twilio's API: Notification's sender reaches the Twilio provider, the
 * webhooks keep Notification's real stop list in step, and the opt-in confirmation is claimed once per number in the
 * real delivery state.
 */
class TwilioTest extends TestCase
{
    private ObjectManagerInterface $om;
    private int $storeId;
    private string $number;
    /** what the stub Twilio API received */
    private array $requests = [];

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $stores = $this->om->get(StoreManagerInterface::class);
        $stores->setCurrentStore('default');
        $this->storeId = (int)$stores->getStore()->getId();
        $this->number = '+38160' . random_int(1000000, 9999999);
    }

    protected function tearDown(): void
    {
        $stopList = $this->om->get(StopListInterface::class);
        foreach ([Channel::SMS, Channel::WHATSAPP] as $channel) {
            $stopList->resume($channel, $this->number);
        }
        $this->om->get(ResourceConnection::class)->getConnection()->delete(
            $this->om->get(DeliveryStateInterface::class)->getTableName(), ['type = ?' => Confirmation::TYPE, 'reference_id >= ?' => 1900000000]);
    }

    private function sender(): Sender
    {
        $config = $this->createStub(Config::class);
        $config->method('getAccountSid')->willReturn('AC_TEST');
        $config->method('getAuthToken')->willReturn('token');
        $config->method('getMessagingServiceSid')->willReturn('MG_TEST');
        $config->method('getWebhookBaseUrl')->willReturn('https://shop.test');
        $client = $this->createStub(TwilioClient::class);
        $client->method('createMessage')->willReturnCallback(function ($account, $token, $params) {
            $this->requests[] = $params;
            return ['sid' => 'SM_TEST'];
        });
        $provider = new SmsProvider($this->om->create(MessageSender::class, ['config' => $config, 'client' => $client]));
        return $this->om->create(Sender::class, [
            'providers' => new ProviderPool($this->om->get(\CommerceKings\Notification\Model\Config::class), [$provider]),
        ]);
    }

    private function text(string $recipient, bool $stoppable = true): Delivery
    {
        return Delivery::text($this->storeId, Channel::SMS, $recipient,
            fn () => new DeliveryContent([], text: 'Your cart is waiting. Reply STOP to stop'), false, null, $stoppable, 'test_sms');
    }

    public function testNotificationSSenderReachesTwilioWithTheNormalizedNumber(): void
    {
        $spaced = substr($this->number, 0, 4) . ' ' . substr($this->number, 4, 2) . ' ' . substr($this->number, 6);
        $this->assertSame(SenderInterface::SENT, $this->sender()->send($this->text($spaced)));
        $this->assertSame(['To' => $this->number, 'MessagingServiceSid' => 'MG_TEST',
            'Body' => 'Your cart is waiting. Reply STOP to stop', 'StatusCallback' => 'https://shop.test/cktwilio/webhook/status'],
            $this->requests[0]);
    }

    /** A STOP reply holds until START; a stopped number gets no marketing but still its service messages. */
    public function testRepliesAndStatusesKeepTheStopListInStep(): void
    {
        $stopList = $this->om->get(StopListInterface::class);
        $this->om->get(InboundProcessor::class)->process(['From' => $this->number, 'Body' => 'STOP']);
        $this->assertSame(StopListInterface::REASON_REPLY, $stopList->getReason(Channel::SMS, $this->number));
        $this->assertSame(SenderInterface::STOPPED, $this->sender()->send($this->text($this->number)));
        $this->assertSame(SenderInterface::SENT, $this->sender()->send($this->text($this->number, false)), 'an order message');
        $this->om->get(InboundProcessor::class)->process(['From' => $this->number, 'Body' => 'START']);
        $this->assertNull($stopList->getReason(Channel::SMS, $this->number));

        $this->om->get(StatusProcessor::class)->process(['To' => 'whatsapp:' . $this->number, 'MessageStatus' => 'undelivered',
            'ErrorCode' => '30003']);
        $this->assertSame(StopListInterface::REASON_UNDELIVERABLE, $stopList->getReason(Channel::WHATSAPP, $this->number));
        $this->assertSame(null, $this->om->get(InboundProcessor::class)->process(['From' => 'not-a-number', 'Body' => 'STOP']),
            'refused by Notification: ignored, answered 200');
    }

    public function testTheRecipientSZoneProviderIsRegisteredForBothChannels(): void
    {
        $zones = (fn () => $this->zones)->call($this->om->get(QuietHours::class));
        $this->assertInstanceOf(NumberZones::class, $zones[Channel::SMS]);
        $this->assertInstanceOf(NumberZones::class, $zones[Channel::WHATSAPP]);
    }

    /** The real delivery state: one confirmation per number, however often it is queued. */
    public function testTheConfirmationIsSentOncePerNumber(): void
    {
        $contactId = random_int(1900000000, 2000000000);
        $repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $repository->method('get')->willReturn(new Subscription(1, 'test_sms', 1, $this->storeId, $contactId, null, '', '2026-10-18 00:00:00'));
        $repository->method('getRecipient')->willReturn(['channel' => Channel::SMS, 'recipient' => $this->number]);
        $sent = 0;
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function () use (&$sent) { $sent++; return SenderInterface::SENT; });
        $confirmation = $this->om->create(Confirmation::class, ['subscriptions' => $repository, 'sender' => $sender]);
        $confirmation->process('1');
        $confirmation->process('1');
        $this->assertSame(1, $sent);
    }
}
