<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Controller\Webhook;

use CommerceKings\NotificationTwilio\Model\Webhook\Signature;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * A Twilio webhook: POST only, authorized by Twilio's signature (not a form key), 403 without doing anything when it
 * fails. Twilio does not send a webhook again after a non-2xx answer, so a valid one is always answered 200.
 */
abstract class AbstractWebhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly RawFactory $rawFactory,
        protected readonly Signature $signature
    ) {
    }

    /** The path Twilio is configured to call, e.g. "/cktwilio/webhook/inbound". */
    abstract protected function path(): string;

    /** @param array<string, mixed> $params */
    abstract protected function handle(array $params): Raw;

    public function execute(): ResultInterface
    {
        $params = $this->request instanceof Http ? (array)$this->request->getPostValue() : [];
        $header = $this->request instanceof Http ? (string)$this->request->getHeader('X-Twilio-Signature') : '';
        if (!$this->signature->isValid($this->path(), $params, $header)) {
            return $this->rawFactory->create()->setHttpResponseCode(403)
                ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)->setContents('Invalid signature.');
        }
        return $this->handle($params);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // Twilio's signature authorizes it (checked in execute())
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
