<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * WhatsApp templates: one row per notification type and locale, with the Content SID and its variables in order. The
 * table takes the whole width of the settings row, so a 34-character Content SID and a type code are not cut.
 */
class TemplateMap extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('type', ['label' => __('Notification type'), 'class' => 'required-entry', 'style' => 'width:210px']);
        $this->addColumn('locale', ['label' => __('Locale'), 'style' => 'width:70px']);
        $this->addColumn('content_sid', ['label' => __('Content SID'), 'class' => 'required-entry', 'style' => 'width:300px']);
        $this->addColumn('variables', ['label' => __('Variables, in order'), 'style' => 'width:220px']);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add template');
    }

    /**
     * The whole row: the label above the table, across the label, value and hint columns, so the values are not cut.
     * A scope's "use default" checkbox keeps its own cell in the same row (Magento toggles the row's inputs with it).
     */
    public function render(AbstractElement $element)
    {
        $checkbox = $this->_isInheritCheckboxRequired($element);
        if ($element->getInherit() == 1 && $checkbox) {
            $element->setDisabled(true);
        }
        if ($element->getIsDisableInheritance()) {
            $element->setReadonly(true);
        }
        $html = '<td class="value" colspan="' . ($checkbox ? 3 : 4) . '">'
            . '<div class="label admin__field-label" style="text-align:left;padding:0 0 10px">'
            . '<label for="' . $element->getHtmlId() . '"><span>' . $element->getLabel() . '</span></label>'
            // the scope inline: Magento's own scope hint is positioned for the label column
            . ($element->getScopeLabel() ? ' <span style="color:#808080;font-size:1.1rem;font-weight:400">'
                . $element->getScopeLabel() . '</span>' : '') . '</div>'
            . $this->_getElementHtml($element);
        if ($element->getComment()) {
            $html .= '<p class="note"><span>' . $element->getComment() . '</span></p>';
        }
        $html .= '</td>';
        if ($checkbox) {
            $html .= $this->_renderInheritCheckbox($element);
        }
        return $this->_decorateRowHtml($element, $html);
    }
}
