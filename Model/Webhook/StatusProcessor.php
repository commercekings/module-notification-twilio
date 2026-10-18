<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model\Webhook;

use CommerceKings\Notification\Api\StopListInterface;
use Psr\Log\LoggerInterface;

/**
 * A delivery status: a message that failed because the number cannot receive it is suppressed
 * (StopListInterface::REASON_UNDELIVERABLE: its consents stay, it lifts after the configured days); one refused because
 * the person replied STOP to this sender (21610) is their opt-out (REASON_REPLY). Other statuses change nothing.
 */
class StatusProcessor
{
    /** Unknown or unreachable destination, landline or unreachable carrier, invalid number, not a mobile number. */
    public const UNDELIVERABLE = [30003, 30005, 30006, 21211, 21614];
    public const UNSUBSCRIBED = 21610;

    public function __construct(
        private readonly StopListInterface $stopList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $params the webhook's POST parameters
     * @return string|null the stop reason applied, or null
     */
    public function process(array $params): ?string
    {
        $status = strtolower((string)($params['MessageStatus'] ?? ''));
        $code = (int)($params['ErrorCode'] ?? 0);
        if (!in_array($status, ['failed', 'undelivered'], true)) {
            return null;
        }
        $reason = match (true) {
            $code === self::UNSUBSCRIBED => StopListInterface::REASON_REPLY,
            in_array($code, self::UNDELIVERABLE, true) => StopListInterface::REASON_UNDELIVERABLE,
            default => null,
        };
        if ($reason === null) {
            return null;
        }
        $to = Address::parse((string)($params['To'] ?? ''));
        try {
            $this->stopList->stop($to['channel'], $to['recipient'], $reason);
        } catch (\InvalidArgumentException) {
            $this->logger->warning(sprintf('CommerceKings_NotificationTwilio: status %d for an address that is not a valid'
                . ' number on the "%s" channel was ignored.', $code, $to['channel']));
            return null;
        }
        return $reason;
    }
}
