<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Controller\Webhook;

use CommerceKings\NotificationTwilio\Model\Webhook\Signature;
use CommerceKings\NotificationTwilio\Model\Webhook\StatusProcessor;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;

/** Delivery status callbacks (StatusCallback of every message): undeliverable numbers and late STOP refusals. */
class Status extends AbstractWebhook
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        Signature $signature,
        private readonly StatusProcessor $processor
    ) {
        parent::__construct($request, $rawFactory, $signature);
    }

    protected function path(): string
    {
        return '/cktwilio/webhook/status';
    }

    protected function handle(array $params): Raw
    {
        $this->processor->process($params);
        return $this->rawFactory->create()->setHttpResponseCode(200)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)->setContents('');
    }
}
