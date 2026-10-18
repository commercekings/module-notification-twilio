<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Webhook;

use CommerceKings\Notification\Api\StopListInterface;
use Psr\Log\LoggerInterface;

/**
 * A reply: STOP and its synonyms ("opt out", "STOP!!" too; see keyword()) and WhatsApp's "Stop
 * promotions" button stop the number on its channel until it sends START (StopListInterface::REASON_REPLY); START and its synonyms lift it (removed subscriptions do not come
 * back). With Twilio's Advanced Opt-Out, Twilio's own OptOutType decides; anything else is left alone.
 */
class InboundProcessor
{
    /** Twilio's default opt-out keywords. */
    public const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];
    /** Twilio's default opt-in keywords. */
    public const START_WORDS = ['START', 'UNSTOP', 'YES'];
    /** The opt-out button of WhatsApp marketing templates. */
    public const STOP_PROMOTIONS = 'stop promotions';

    public function __construct(
        private readonly StopListInterface $stopList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $params the webhook's POST parameters
     * @return string|null "stop", "start" or null when the reply is neither
     */
    public function process(array $params): ?string
    {
        $action = $this->action($params);
        if ($action === null) {
            $this->logger->info('CommerceKings_NotificationTwilio: unrecognized reply.'); // no number, no text
            return null;
        }
        $from = Address::parse((string)($params['From'] ?? ''));
        try {
            if ($action === 'stop') {
                $this->stopList->stop($from['channel'], $from['recipient'], StopListInterface::REASON_REPLY);
            } else {
                $this->stopList->resume($from['channel'], $from['recipient']);
            }
        } catch (\InvalidArgumentException) {
            // not a number Notification can take: nothing to stop; Twilio still gets 200 (it would not send it again)
            $this->logger->warning(sprintf('CommerceKings_NotificationTwilio: a %s reply from an address that is not a'
                . ' valid number on the "%s" channel was ignored.', strtoupper($action), $from['channel']));
            return null;
        }
        return $action;
    }

    /** @param array<string, mixed> $params */
    private function action(array $params): ?string
    {
        $optOut = strtoupper(trim((string)($params['OptOutType'] ?? '')));
        if ($optOut === 'STOP') {
            return 'stop';
        }
        if ($optOut === 'START') {
            return 'start';
        }
        foreach (['ButtonPayload', 'ButtonText', 'Body'] as $field) {
            if (strtolower(trim((string)($params[$field] ?? ''))) === self::STOP_PROMOTIONS) {
                return 'stop';
            }
        }
        return $this->keyword((string)($params['Body'] ?? '')) ?: null;
    }

    /**
     * The keyword of a reply: the whole message is exactly a keyword, ignoring case, spaces, hyphens, punctuation and
     * emoji ("Opt-out!" is OPTOUT, "stop all" STOPALL) - the industry rule; "stop please" is none (the provider's own
     * opt-out handling and the merchant cover those).
     */
    private function keyword(string $text): string
    {
        $word = (string)preg_replace('/[^\p{L}]+/u', '', mb_strtoupper($text, 'UTF-8'));
        return match (true) {
            in_array($word, self::STOP_WORDS, true) => 'stop',
            in_array($word, self::START_WORDS, true) => 'start',
            default => '',
        };
    }
}
