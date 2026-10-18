<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Model;

/**
 * WhatsApp templates (Twilio Content SIDs, HX…) per notification type and locale (*Twilio > WhatsApp templates*): the
 * row for the type and the store's locale, else for the type and the locale's language ("de" for "de_CH"), else for
 * the type alone (empty locale). The variables are the DeliveryContent::$vars names in the template's order
 * ({{1}}, {{2}}, …).
 */
class TemplateMap
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @return array{content_sid: string, variables: string[]}|null */
    public function find(int $storeId, string $type, string $locale): ?array
    {
        if ($type === '') {
            return null;
        }
        $language = strtolower(explode('_', $locale)[0]);
        $best = null;
        $bestRank = 0;
        foreach ($this->config->getTemplateMap($storeId) as $row) {
            if (trim((string)($row['type'] ?? '')) !== $type || trim((string)($row['content_sid'] ?? '')) === '') {
                continue;
            }
            $rowLocale = trim((string)($row['locale'] ?? ''));
            $rank = match (true) {
                strcasecmp($rowLocale, $locale) === 0 => 3,
                $rowLocale !== '' && strcasecmp($rowLocale, $language) === 0 => 2,
                $rowLocale === '' => 1,
                default => 0,
            };
            if ($rank > $bestRank) {
                [$best, $bestRank] = [$row, $rank];
            }
        }
        if ($best === null) {
            return null;
        }
        $variables = array_values(array_filter(array_map('trim', explode(',', (string)($best['variables'] ?? ''))),
            fn (string $name) => $name !== ''));
        return ['content_sid' => trim((string)$best['content_sid']), 'variables' => $variables];
    }
}
