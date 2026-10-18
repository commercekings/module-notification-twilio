<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

/** WhatsApp templates: one row per notification type and locale, with the Content SID and its variables in order. */
class TemplateMap extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('type', ['label' => __('Notification type'), 'class' => 'required-entry']);
        $this->addColumn('locale', ['label' => __('Locale')]);
        $this->addColumn('content_sid', ['label' => __('Content SID'), 'class' => 'required-entry']);
        $this->addColumn('variables', ['label' => __('Variables, in order')]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add template');
    }
}
