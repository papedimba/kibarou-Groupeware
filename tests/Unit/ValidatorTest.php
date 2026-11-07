<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ValidationException;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testValidDataIsTrimmedAndReturned(): void
    {
        $out = Validator::validate(['email' => '  a@b.co ', 'age' => '12'], ['email' => 'required|email', 'age' => 'int', 'opt' => 'max:3']);
        $this->assertSame(['email' => 'a@b.co', 'age' => '12', 'opt' => null], $out);
    }

    public function testCollectsOneErrorPerField(): void
    {
        try {
            Validator::validate(['email' => 'nope', 'name' => str_repeat('x', 11), 'role' => 'root'], [
                'email' => 'required|email', 'name' => 'max:10', 'role' => 'in:a,b', 'missing' => 'required',
            ], ['missing' => 'Nom']);
            $this->fail('Exception attendue');
        } catch (ValidationException $e) {
            $this->assertSame(['email', 'name', 'role', 'missing'], array_keys($e->errors));
            $this->assertStringContainsString('Nom', $e->errors['missing']);
        }
    }

    public function testArrayValueIsRejectedForScalarRules(): void
    {
        $this->expectException(ValidationException::class);
        Validator::validate(['email' => ['x']], ['email' => 'email']);
    }
}
