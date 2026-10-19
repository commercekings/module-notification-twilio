<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{array<string, mixed>, bool}> cURL's report => whether the message may have been sent */
    public static function connectFailures(): array
    {
        return [
            'connection refused' => [['errno' => 7], false],
            'host not found' => [['errno' => 6], false],
            'proxy not found' => [['errno' => 5], false],
            'TLS handshake failed' => [['errno' => 35], false],
            'timeout while connecting' => [['errno' => 28, 'pretransfer_time' => 0.0, 'request_size' => 0, 'size_upload' => 0.0], false],
            'timeout after the request went out' => [['errno' => 28, 'pretransfer_time' => 0.21, 'request_size' => 412,
                'size_upload' => 380.0], true],
            'timeout without a transfer report' => [['errno' => 28], true],
            'timeout, headers sent' => [['errno' => 28, 'pretransfer_time' => 0.0, 'request_size' => 412], true],
            'empty reply' => [['errno' => 52], true],
            'no report at all' => [[], true],
        ];
    }

    /**
     * cURL reports a timeout while connecting and while waiting for the answer alike: only a failure that provably
     * happened before the request left is "nothing sent" (and retried); anything else may have been sent – never again.
     */
    #[DataProvider('connectFailures')]
    public function testOnlyAProvenConnectFailureIsNotSent(array $context, bool $maybeSent): void
    {
        $failure = new ConnectException('cURL error', new Request('POST', 'https://api.twilio.com'), null, $context);
        try {
            $this->client([$failure])->createMessage('AC1', 't', []);
            $this->fail('Expected an exception');
        } catch (MessageSentException $error) {
            $this->assertTrue($maybeSent, 'reported as maybe sent: ' . $error->getMessage());
        } catch (\RuntimeException $error) {
            $this->assertFalse($maybeSent, 'reported as not sent: ' . $error->getMessage());
            $this->assertStringContainsString('nothing was sent', $error->getMessage());
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

    /** A rate limit, or a short outage with a stated wait: nothing was sent, send it again soon. */
    public function testA429OrA503WithRetryAfterIsATemporaryRefusal(): void
    {
        foreach ([[new Response(429, ['Retry-After' => '30'], '{"code":20429,"message":"Too Many Requests"}'), 30],
            [new Response(429, [], '{"code":20429,"message":"Too Many Requests"}'), null],
            [new Response(503, ['Retry-After' => 'Mon, 19 Oct 2099 10:05:00 GMT'], ''), 'date'],
            [new Response(503, ['Retry-After' => '120'], ''), 120]] as [$response, $wait]) {
            try {
                $this->client([$response])->createMessage('AC1', 't', []);
                $this->fail('Expected a temporary refusal');
            } catch (TemporaryFailureException $error) {
                $this->assertStringContainsString('HTTP ' . $response->getStatusCode(), $error->getMessage());
                $wait === 'date' ? $this->assertGreaterThan(0, $error->getRetryAfter())
                    : $this->assertSame($wait, $error->getRetryAfter());
            }
        }
    }

    /** A server error may come after the message was taken: never send it twice. A 503 without a wait is one too. */
    public function testOtherServerErrorsMayHaveBeenSent(): void
    {
        foreach ([500, 502, 503, 504] as $status) {
            try {
                $this->client([new Response($status, [], '')])->createMessage('AC1', 't', []);
                $this->fail('Expected maybe sent for ' . $status);
            } catch (MessageSentException $error) {
                $this->assertStringContainsString('HTTP ' . $status, $error->getMessage());
            }
        }
    }

    public function testOtherClientErrorsStayRefusals(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            try {
                $this->client([new Response($status, ['Retry-After' => '30'], '{}')])->createMessage('AC1', 't', []);
                $this->fail('Expected a refusal for ' . $status);
            } catch (TwilioException $error) {
                $this->assertSame($status, $error->getCode());
            }
        }
    }
}
