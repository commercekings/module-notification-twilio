<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationTwilio\Exception\TwilioException;
use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\Http\TwilioClient;
use CommerceKings\NotificationTwilio\Model\MessageSender;
use CommerceKings\NotificationTwilio\Model\TemplateMap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class MessageSenderTest extends TestCase
{
    private array $sent = [];
    private array $stops = [];
    private array $settings = ['account' => 'AC1', 'token' => 't', 'service' => '', 'sms' => '+14155550100',
        'whatsapp' => '+14155238886', 'base' => 'https://shop.test', 'map' => []];
    private ?\Throwable $failure = null;

    private function sender(): MessageSender
    {
        $config = $this->createStub(Config::class);
        $config->method('getAccountSid')->willReturnCallback(fn () => $this->settings['account']);
        $config->method('getAuthToken')->willReturnCallback(fn () => $this->settings['token']);
        $config->method('getMessagingServiceSid')->willReturnCallback(fn () => $this->settings['service']);
        $config->method('getSmsFrom')->willReturnCallback(fn () => $this->settings['sms']);
        $config->method('getWhatsappFrom')->willReturnCallback(fn () => $this->settings['whatsapp']);
        $config->method('getWebhookBaseUrl')->willReturnCallback(fn () => $this->settings['base']);
        $config->method('getTemplateMap')->willReturnCallback(fn () => $this->settings['map']);
        $client = $this->createStub(TwilioClient::class);
        $client->method('createMessage')->willReturnCallback(function ($account, $token, $params) {
            $this->sent[] = [$account, $token, $params];
            if ($this->failure) {
                throw $this->failure;
            }
            return ['sid' => 'SM1'];
        });
        $stopList = $this->createStub(StopListInterface::class);
        $stopList->method('stop')->willReturnCallback(function (...$arguments) { $this->stops[] = $arguments; });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn('de_CH');
        return new MessageSender($config, $client, new TemplateMap($config), $stopList, $scope);
    }

    private function sms(string $text = 'Your cart is waiting. Reply STOP to stop'): array
    {
        return [Delivery::text(3, Channel::SMS, '+381601234567', fn () => null, type: 'cart_reminder_sms'),
            new DeliveryContent([], text: $text)];
    }

    public function testAnSmsGoesFromTheNumberWithItsTextAndAskSForTheStatus(): void
    {
        $this->sender()->send(...$this->sms());
        $this->assertSame(['AC1', 't', ['To' => '+381601234567', 'From' => '+14155550100',
            'Body' => 'Your cart is waiting. Reply STOP to stop',
            'StatusCallback' => 'https://shop.test/cktwilio/webhook/status']], $this->sent[0]);
        $this->settings['service'] = 'MG1';
        $this->sender()->send(...$this->sms());
        $this->assertSame('MG1', $this->sent[1][2]['MessagingServiceSid'], 'a Messaging Service wins for SMS');
        $this->assertArrayNotHasKey('From', $this->sent[1][2]);
    }

    public function testAnSmsNeedsATextAndASender(): void
    {
        foreach ([fn () => $this->sender()->send(...$this->sms('  ')),
            function () { $this->settings['sms'] = ''; $this->sender()->send(...$this->sms()); }] as $call) {
            try {
                $call();
                $this->fail('Expected an exception');
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function testAWhatsappMessageIsTheTypeSTemplateForTheLocaleWithItsVariablesInOrder(): void
    {
        $this->settings['map'] = [
            ['type' => 'cart_reminder_whatsapp', 'locale' => '', 'content_sid' => 'HXany', 'variables' => ''],
            ['type' => 'cart_reminder_whatsapp', 'locale' => 'de', 'content_sid' => 'HXde', 'variables' => 'customer_name, restore_url'],
            ['type' => 'cart_reminder_whatsapp', 'locale' => 'fr_FR', 'content_sid' => 'HXfr', 'variables' => ''],
        ];
        $this->sender()->send(Delivery::text(3, Channel::WHATSAPP, '+381601234567', fn () => null, type: 'cart_reminder_whatsapp'),
            new DeliveryContent(['customer_name' => 'Ana', 'restore_url' => 'https://shop.test/r', 'items_html' => '<b>']));
        $params = $this->sent[0][2];
        $this->assertSame(['whatsapp:+381601234567', 'whatsapp:+14155238886', 'HXde'],
            [$params['To'], $params['From'], $params['ContentSid']], 'de_CH falls back to the language');
        $this->assertSame(['1' => 'Ana', '2' => 'https://shop.test/r'], json_decode($params['ContentVariables'], true));
    }

    public function testAWhatsappMessageWithoutATemplateIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->sender()->send(Delivery::text(3, Channel::WHATSAPP, '+381601234567', fn () => null, type: 'unknown'),
            new DeliveryContent([], text: 'free text is not allowed outside a conversation'));
    }

    /** Twilio refusing a number that replied STOP: the stop list learns it (reason reply), the send fails as refused. */
    public function testARefusalForAnUnsubscribedNumberStopsIt(): void
    {
        $this->failure = new TwilioException('unsubscribed', 21610, 400);
        try {
            $this->sender()->send(...$this->sms());
            $this->fail('Expected the refusal');
        } catch (TwilioException) {
        }
        $this->assertSame([[Channel::SMS, '+381601234567', StopListInterface::REASON_REPLY]], $this->stops);
        foreach ([21608, 21606, 21212, 21659, 20003] as $code) { // trial number not verified, From not ours or invalid, auth
            try {
                $this->failure = new TwilioException('account', $code, 400);
                $this->sender()->send(...$this->sms());
            } catch (TwilioException) {
            }
        }
        $this->assertCount(1, $this->stops, 'account and sender refusals never stop the shopper');
    }

    /** Twilio refusing an invalid (21211) or non-mobile (21614) number: it is suppressed, the send fails as refused. */
    public function testARefusalForANumberThatCannotReceiveSuppressesIt(): void
    {
        foreach ([21211, 21614] as $code) {
            $this->failure = new TwilioException('number', $code, 400);
            try {
                $this->sender()->send(...$this->sms());
                $this->fail('Expected the refusal');
            } catch (TwilioException $error) {
                $this->assertSame($code, $error->getTwilioCode());
            }
        }
        $this->assertSame([[Channel::SMS, '+381601234567', StopListInterface::REASON_UNDELIVERABLE],
            [Channel::SMS, '+381601234567', StopListInterface::REASON_UNDELIVERABLE]], $this->stops);
    }
}
