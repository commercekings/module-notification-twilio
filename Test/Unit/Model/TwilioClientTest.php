<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\NotificationTwilio\Exception\TwilioException;
use CommerceKings\NotificationTwilio\Model\Http\TwilioClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/** What a failure means: refused (nothing sent), unreachable (nothing sent) or no answer (maybe sent: never again). */
class TwilioClientTest extends TestCase
{
    private array $history = [];

    private function client(array $responses): TwilioClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        return new TwilioClient(new Client(['handler' => $stack]));
    }

    public function testACreatedMessageIsPostedWithBasicAuthToTheAccount(): void
    {
        $result = $this->client([new Response(201, [], '{"sid":"SM1","status":"queued"}')])
            ->createMessage('AC1', 'token', ['To' => '+381601234567', 'Body' => 'Hi']);
        $this->assertSame('SM1', $result['sid']);
        $request = $this->history[0]['request'];
        $this->assertSame('https://api.twilio.com/2010-04-01/Accounts/AC1/Messages.json', (string)$request->getUri());
        $this->assertSame('Basic ' . base64_encode('AC1:token'), $request->getHeaderLine('Authorization'));
        parse_str((string)$request->getBody(), $form);
        $this->assertSame(['To' => '+381601234567', 'Body' => 'Hi'], $form);
    }

    public function testARefusalCarriesTwilioSCode(): void
    {
        try {
            $this->client([new Response(400, [], '{"code":21211,"message":"Invalid To"}')])->createMessage('AC1', 't', []);
            $this->fail('Expected a refusal');
        } catch (TwilioException $error) {
            $this->assertSame([21211, 400], [$error->getTwilioCode(), $error->getCode()]);
        }
    }

    public function testUnreachableIsNotSentButNoAnswerMayBe(): void
    {
        $request = new Request('POST', 'https://api.twilio.com');
        try {
            $this->client([new ConnectException('refused', $request, null, ['errno' => 7])])->createMessage('AC1', 't', []);
            $this->fail('Expected an exception');
        } catch (MessageSentException) {
            $this->fail('A refused connection sent nothing');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('nothing was sent', $error->getMessage());
        }
        foreach ([new RequestException('timed out', $request), new ConnectException('empty reply', $request, null, ['errno' => 52])] as $failure) {
            try {
                $this->client([$failure])->createMessage('AC1', 't', []);
                $this->fail('Expected MessageSentException');
            } catch (MessageSentException $error) {
                $this->assertStringContainsString('may have been sent', $error->getMessage());
            }
        }
    }

    public function testWithoutCredentialsNothingIsRequested(): void
    {
        $this->expectException(\RuntimeException::class);
        try {
            $this->client([])->createMessage('', 'token', []);
        } finally {
            $this->assertSame([], $this->history);
        }
    }
}
