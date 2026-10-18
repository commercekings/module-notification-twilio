<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Controller\Webhook;

use CommerceKings\NotificationTwilio\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationTwilio\Model\Webhook\Signature;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

/**
 * Replies to the shop's numbers (Twilio: "A message comes in"): STOP / START keep Notification's stop list in step.
 * Answers empty TwiML: Twilio's Advanced Opt-Out (or the shop) sends the confirmation text.
 */
class Inbound extends AbstractWebhook
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Signature $signature,
        private readonly InboundProcessor $processor
    ) {
        parent::__construct($request, $rawFactory, $signature);
    }

    protected function path(): string
    {
        return '/cktwilio/webhook/inbound';
    }

    protected function handle(array $params): Raw
    {
        $this->processor->process($params);
        return $this->rawFactory->create()->setHttpResponseCode(200)
            ->setHeader('Content-Type', 'text/xml; charset=UTF-8', true)
            ->setContents('<?xml version="1.0" encoding="UTF-8"?><Response/>');
    }
}
