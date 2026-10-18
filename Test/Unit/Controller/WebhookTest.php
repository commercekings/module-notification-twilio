<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Controller;

use CommerceKings\NotificationTwilio\Controller\Webhook\Inbound;
use CommerceKings\NotificationTwilio\Controller\Webhook\Status;
use CommerceKings\NotificationTwilio\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationTwilio\Model\Webhook\Signature;
use CommerceKings\NotificationTwilio\Model\Webhook\StatusProcessor;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private ?int $httpStatus = null;
    private string $body = '';
    private array $processed = [];
    private bool $valid = true;
    private array $checked = [];

    private function parts(): array
    {
        $request = $this->createStub(Http::class);
        $request->method('getPostValue')->willReturn(['AccountSid' => 'AC1', 'From' => '+381601234567', 'Body' => 'STOP']);
        $request->method('getHeader')->willReturn('sig');
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) { $this->httpStatus = $code; return $raw; });
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnCallback(function ($body) use ($raw) { $this->body = $body; return $raw; });
        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);
        $signature = $this->createStub(Signature::class);
        $signature->method('isValid')->willReturnCallback(function ($path, $params, $header) {
            $this->checked[] = [$path, $header];
            return $this->valid;
        });
        return [$request, $factory, $signature];
    }

    public function testAValidReplyIsProcessedAndAnsweredWithEmptyTwiml(): void
    {
        $processor = $this->createStub(InboundProcessor::class);
        $processor->method('process')->willReturnCallback(function ($params) { $this->processed[] = $params; return 'stop'; });
        (new Inbound(...[...$this->parts(), $processor]))->execute();
        $this->assertSame([200, ['/cktwilio/webhook/inbound', 'sig']], [$this->httpStatus, $this->checked[0]]);
        $this->assertStringContainsString('<Response/>', $this->body);
        $this->assertCount(1, $this->processed);
    }

    public function testABadSignatureIs403AndNothingHappens(): void
    {
        $this->valid = false;
        $processor = $this->createStub(StatusProcessor::class);
        $processor->method('process')->willReturnCallback(function ($params) { $this->processed[] = $params; return null; });
        (new Status(...[...$this->parts(), $processor]))->execute();
        $this->assertSame([403, '/cktwilio/webhook/status'], [$this->httpStatus, $this->checked[0][0]]);
        $this->assertSame([], $this->processed);
    }

    public function testTheSignatureReplacesTheFormKey(): void
    {
        $inbound = new Inbound(...[...$this->parts(), $this->createStub(InboundProcessor::class)]);
        $this->assertTrue($inbound->validateForCsrf($this->createStub(Http::class)));
        $this->assertNull($inbound->createCsrfValidationException($this->createStub(Http::class)));
    }
}
