<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Exception;

/** Twilio refused a request (nothing was sent); getTwilioCode() is Twilio's error code, e.g. 21610 or 21211. */
class TwilioException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $twilioCode = 0, int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }

    public function getTwilioCode(): int
    {
        return $this->twilioCode;
    }
}
