<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model\Webhook;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationTwilio\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationTwilio\Model\Webhook\StatusProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Replies and delivery statuses keep Notification's stop list in step with what the person and the carrier said. */
class ProcessorsTest extends TestCase
{
    private array $calls = [];
    private bool $invalid = false;

    private function stopList(): StopListInterface
    {
        $stopList = $this->createStub(StopListInterface::class);
        foreach (['stop', 'resume'] as $method) {
            $stopList->method($method)->willReturnCallback(function (...$arguments) use ($method) {
                if ($this->invalid) {
                    throw new \InvalidArgumentException('No valid recipient');
                }
                // Twilio sends no receive time: the optional request time (StopListInterface) stays null
                $this->assertNull(array_pop($arguments));
                $this->calls[] = [$method, ...$arguments];
            });
        }
        return $stopList;
    }

    private function inbound(array $params): ?string
    {
        return (new InboundProcessor($this->stopList(), $this->createStub(LoggerInterface::class)))->process($params);
    }

    private function statusOf(array $params): ?string
    {
        return (new StatusProcessor($this->stopList(), $this->createStub(LoggerInterface::class)))->process($params);
    }

    public function testStopWordsStopUntilStart(): void
    {
        foreach (['STOP', ' stop. ', 'Unsubscribe', 'quit'] as $body) {
            $this->assertSame('stop', $this->inbound(['From' => '+381601234567', 'Body' => $body]), $body);
        }
        $this->assertSame(['stop', Channel::SMS, '+381601234567', StopListInterface::REASON_REPLY], $this->calls[0]);
        $this->assertSame('start', $this->inbound(['From' => '+381601234567', 'Body' => 'START']));
        $this->assertSame(['resume', Channel::SMS, '+381601234567'], end($this->calls));
    }

    public function testOnlyAWholeReplyThatIsAKeywordCountsIgnoringCaseSpacesAndPunctuation(): void
    {
        foreach (['opt out', 'Opt-Out', 'OPT OUT!', 'optout', 'stop all', 'STOP ALL', 'STOP!!', 'stop.', ' Stop ',
            'Stop 🛑', '🛑 stop', 'Revoke', 'stopall', 'Un-subscribe', 'cancel', 'QUIT!', 'e.n.d'] as $text) {
            $this->assertSame('stop', $this->inbound(['From' => '+381601234567', 'Body' => $text]), $text);
        }
        foreach (['stop please', 'Stop texting me', 'Cancel my order', 'Unsubscribe me', 'opt out now', 'stopwatch',
            "Don't stop", 'please stop the music', 'nonstop', 'opt', 'stopped', 'HELP', 'Yes stop', 'yes please',
            'start now', 'Started', 'unstoppable', '🙂', ''] as $text) {
            $this->assertSame(null, $this->inbound(['From' => '+381601234567', 'Body' => $text]), $text);
        }
        foreach (['START', 'start!', ' Unstop ', 'yes', 'Yes!', 'YES 👍', 'un-stop'] as $text) {
            $this->assertSame('start', $this->inbound(['From' => '+381601234567', 'Body' => $text]), $text);
        }
    }

    public function testAnUnrecognizedReplyIsLoggedWithoutTheNumberOrText(): void
    {
        $logged = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function ($message) use (&$logged) { $logged[] = $message; });
        $this->assertNull((new InboundProcessor($this->stopList(), $logger))->process(['From' => '+381601234567',
            'Body' => 'Where is my parcel?']));
        $this->assertSame(['CommerceKings_NotificationTwilio: unrecognized reply.'], $logged);
    }

    public function testTwilioSOptOutTypeAndWhatsappSStopPromotionsButton(): void
    {
        $this->assertSame('start', $this->inbound(['From' => '+381601234567', 'Body' => 'go', 'OptOutType' => 'START']));
        $this->assertSame('stop', $this->inbound(['From' => 'whatsapp:+381601234567', 'ButtonText' => 'Stop promotions']));
        $this->assertSame(['stop', Channel::WHATSAPP, '+381601234567', StopListInterface::REASON_REPLY], end($this->calls));
    }

    public function testOtherRepliesAndInvalidSendersChangeNothing(): void
    {
        $this->assertNull($this->inbound(['From' => '+381601234567', 'Body' => 'please stop the music']));
        $this->assertNull($this->inbound(['From' => '+381601234567', 'Body' => 'HELP']));
        $this->assertSame([], $this->calls);
        $this->invalid = true;
        $this->assertNull($this->inbound(['From' => 'shortcode', 'Body' => 'STOP']), 'refused by the stop list: answered 200');
    }

    public function testANumberWithoutPlusGetsIt(): void
    {
        $this->inbound(['From' => '381601234567', 'Body' => 'STOP']);
        $this->assertSame('+381601234567', $this->calls[0][2]);
    }

    /** Errors of the shop's account or sender (trial, From, Messaging Service, verification) never stop the shopper. */
    public function testAccountAndSenderErrorsStopNobody(): void
    {
        foreach (['21608', '21606', '21212', '21659', '21612', '20003', '30007', '30032', '30034', '63016', '63112'] as $code) {
            foreach (['failed', 'undelivered'] as $status) {
                $this->assertNull($this->statusOf(['To' => '+381601234567', 'MessageStatus' => $status, 'ErrorCode' => $code]),
                    "$status $code");
            }
        }
        $this->assertSame([], $this->calls);
    }

    public function testUndeliverableNumbersAreSuppressedAndAStopRefusalIsAnOptOut(): void
    {
        $this->assertSame(StopListInterface::REASON_UNDELIVERABLE,
            $this->statusOf(['To' => '+381601234567', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30003']));
        $this->assertSame(StopListInterface::REASON_REPLY,
            $this->statusOf(['To' => 'whatsapp:+381601234567', 'MessageStatus' => 'failed', 'ErrorCode' => '21610']));
        $this->assertSame(['stop', Channel::WHATSAPP, '+381601234567', StopListInterface::REASON_REPLY], end($this->calls));
        $this->assertNull($this->statusOf(['To' => '+381601234567', 'MessageStatus' => 'delivered']));
        $this->assertNull($this->statusOf(['To' => '+381601234567', 'MessageStatus' => 'failed', 'ErrorCode' => '30007']),
            'filtered by the carrier: not the number\'s fault');
        $this->assertCount(2, $this->calls);
    }
}
