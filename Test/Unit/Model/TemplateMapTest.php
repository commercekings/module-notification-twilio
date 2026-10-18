<?php
declare(strict_types=1);

namespace CommerceKings\NotificationTwilio\Test\Unit\Model;

use CommerceKings\NotificationTwilio\Model\Config;
use CommerceKings\NotificationTwilio\Model\TemplateMap;
use PHPUnit\Framework\TestCase;

class TemplateMapTest extends TestCase
{
    private function map(array $rows): TemplateMap
    {
        $config = $this->createStub(Config::class);
        $config->method('getTemplateMap')->willReturn($rows);
        return new TemplateMap($config);
    }

    public function testTheExactLocaleThenTheLanguageThenAny(): void
    {
        $map = $this->map([
            ['type' => 't', 'locale' => '', 'content_sid' => 'HXany', 'variables' => ''],
            ['type' => 't', 'locale' => 'de', 'content_sid' => 'HXde', 'variables' => 'a,b'],
            ['type' => 't', 'locale' => 'de_AT', 'content_sid' => 'HXat', 'variables' => ' b , ,a'],
            ['type' => 'other', 'locale' => 'de_CH', 'content_sid' => 'HXother', 'variables' => ''],
        ]);
        $this->assertSame(['content_sid' => 'HXat', 'variables' => ['b', 'a']], $map->find(1, 't', 'de_AT'));
        $this->assertSame('HXde', $map->find(1, 't', 'de_CH')['content_sid']);
        $this->assertSame('HXany', $map->find(1, 't', 'fr_FR')['content_sid']);
        $this->assertNull($map->find(1, 'missing', 'de_DE'));
        $this->assertNull($map->find(1, '', 'de_DE'), 'a delivery of an unknown type');
    }
}
