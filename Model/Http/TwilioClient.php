<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Http;

use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\NotificationTwilio\Exception\TwilioException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Twilio's REST API (Messages). What a failure means for the sender:
 * - Twilio answered with an error: nothing was sent – TwilioException with Twilio's code;
 * - no connection: nothing was sent – \RuntimeException;
 * - connected, then no answer (a timeout): it may have been sent – MessageSentException, so it is never sent twice.
 */
class TwilioClient
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01/Accounts/';

    public function __construct(
        private readonly ClientInterface $http,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15
    ) {
    }

    /**
     * Creates a message.
     *
     * @param array<string, string> $params Twilio's form parameters (To, From or MessagingServiceSid, Body or ContentSid, …)
     * @return array<string, mixed> the message resource (sid, status, …)
     */
    public function createMessage(string $accountSid, string $authToken, array $params): array
    {
        if ($accountSid === '' || $authToken === '') {
            throw new \RuntimeException('Twilio is not configured for this store: account SID and auth token are needed.');
        }
        try {
            $response = $this->http->request('POST', self::BASE_URL . rawurlencode($accountSid) . '/Messages.json', [
                'auth' => [$accountSid, $authToken],
                'form_params' => $params,
                'connect_timeout' => $this->connectTimeout,
                'timeout' => $this->timeout,
                'http_errors' => false,
            ]);
        } catch (ConnectException $error) {
            // an empty reply (cURL 52) comes after the request was sent: it may have been accepted
            if ((int)($error->getHandlerContext()['errno'] ?? 0) === 52) {
                throw new MessageSentException('Twilio sent no reply; the message may have been sent.', 0, $error);
            }
            throw new \RuntimeException('Twilio could not be reached; nothing was sent.', 0, $error);
        } catch (RequestException $error) {
            if ($error->getResponse() === null) {
                throw new MessageSentException('Twilio did not answer in time; the message may have been sent.', 0, $error);
            }
            throw new \RuntimeException('Twilio request failed.', 0, $error);
        } catch (GuzzleException $error) {
            throw new MessageSentException('Twilio did not answer; the message may have been sent.', 0, $error);
        }
        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);
        $body = is_array($body) ? $body : [];
        if ($status >= 200 && $status < 300) {
            return $body;
        }
        throw new TwilioException(sprintf('Twilio refused the message (HTTP %d, error %d).', $status, (int)($body['code'] ?? 0)),
            (int)($body['code'] ?? 0), $status);
    }
}
