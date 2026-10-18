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
 * - no connection (proven by cURL's report): nothing was sent – \RuntimeException;
 * - anything else, e.g. a timeout waiting for the answer: it may have been sent – MessageSentException, so it is
 *   never sent twice.
 */
class TwilioClient
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01/Accounts/';

    /** cURL errors before the request left: proxy or host not resolved, connection refused, TLS handshake. */
    private const NOT_SENT_ERRNOS = [5, 6, 7, 35];
    private const ERRNO_TIMEOUT = 28;

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
            if (self::neverSent($error->getHandlerContext())) {
                throw new \RuntimeException('Twilio could not be reached; nothing was sent.', 0, $error);
            }
            // a timeout waiting for the answer, an empty reply (cURL 52), …: it may have been accepted
            throw new MessageSentException('Twilio did not answer; the message may have been sent.', 0, $error);
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

    /**
     * Whether cURL's report proves the request never left: the proxy or host was not found (5, 6), the connection was
     * refused (7) or TLS failed (35) - or a timeout (28) before any transfer began (pretransfer_time 0, nothing sent).
     * cURL reports a timeout while connecting and while waiting for the answer alike, so a timeout without that proof,
     * and any other failure, may have been sent.
     */
    private static function neverSent(array $context): bool
    {
        $errno = (int)($context['errno'] ?? 0);
        if (in_array($errno, self::NOT_SENT_ERRNOS, true)) {
            return true;
        }
        return $errno === self::ERRNO_TIMEOUT && isset($context['pretransfer_time'])
            && (float)$context['pretransfer_time'] <= 0.0 && (int)($context['request_size'] ?? 0) === 0
            && (float)($context['size_upload'] ?? 0) <= 0.0;
    }
}
