<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testAcceptsStrongPassword(): void
    {
        $this->assertNull(PasswordPolicy::check('Chantier2026!', 10, 'jean@kibarou.com'));
    }

    public function testRejections(): void
    {
        $this->assertNotNull(PasswordPolicy::check('court1', 10));
        $this->assertNotNull(PasswordPolicy::check('sanschiffreici', 10));
        $this->assertNotNull(PasswordPolicy::check('1234567890123', 10));
        $this->assertNotNull(PasswordPolicy::check('jeandupont2026', 10, 'jeandupont@kibarou.com'));
        $this->assertNotNull(PasswordPolicy::check(str_repeat('a1', 40), 10));
    }

    public function testHashVerifies(): void
    {
        $this->assertTrue(password_verify('Chantier2026', PasswordPolicy::hash('Chantier2026')));
    }
}
