<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testEscapeNeutralisesHtmlAndAttributes(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;&apos;', e('<script>alert("x")</script>\''));
    }

    public function testInitials(): void
    {
        $this->assertSame('ÉK', initials('élodie', 'koné'));
    }
}
