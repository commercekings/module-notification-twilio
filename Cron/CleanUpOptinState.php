<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Cron;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\NotificationTwilio\Model\Optin\Confirmation;
use Magento\Framework\App\ResourceConnection;

/**
 * Daily: removes the opt-in confirmation's "sent" rows of numbers Notification no longer keeps (the contact was
 * removed with its last subscription). Notification's own cleanup does not know this type's references.
 */
class CleanUpOptinState
{
    private const BATCH = 500;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $state,
        private readonly ContactRepositoryInterface $contacts
    ) {
    }

    public function execute(): int
    {
        $db = $this->resource->getConnection();
        $removed = 0;
        $after = 0;
        do {
            $ids = array_map('intval', $db->fetchCol($db->select()->from($this->state->getTableName(), 'reference_id')
                ->where('type = ?', Confirmation::TYPE)->where('reference_id > ?', $after)
                ->order('reference_id')->limit(self::BATCH)));
            $gone = array_values(array_filter($ids, fn (int $id) => $this->contacts->getRecipient($id) === null));
            if ($gone) {
                $removed += $db->delete($this->state->getTableName(),
                    ['type = ?' => Confirmation::TYPE, 'reference_id IN (?)' => $gone]);
            }
            $after = $ids ? end($ids) : $after;
        } while (count($ids) === self::BATCH);
        return $removed;
    }
}
