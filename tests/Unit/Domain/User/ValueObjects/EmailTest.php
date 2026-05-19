<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\User\ValueObjects;

use App\Domain\User\ValueObjects\Email;
use App\Domain\Shared\Exceptions\ValidationException;
use CodeIgniter\Test\CIUnitTestCase;

final class EmailTest extends CIUnitTestCase
{
    public function testValidEmailIsCreated(): void
    {
        $email = Email::fromString('test@example.com');
        $this->assertSame('test@example.com', $email->getValue());
    }

    public function testEmailIsNormalizedToLowercase(): void
    {
        $email = Email::fromString('TEST@EXAMPLE.COM');
        $this->assertSame('test@example.com', $email->getValue());
    }

    public function testEmailIsTrimmed(): void
    {
        $email = Email::fromString('  test@example.com  ');
        $this->assertSame('test@example.com', $email->getValue());
    }

    public function testEmptyEmailThrowsException(): void
    {
        $this->expectException(ValidationException::class);
        Email::fromString('');
    }

    public function testInvalidEmailFormatThrowsException(): void
    {
        $this->expectException(ValidationException::class);
        Email::fromString('invalid-email');
    }

    public function testEmailWithoutAtSymbolThrowsException(): void
    {
        $this->expectException(ValidationException::class);
        Email::fromString('testexample.com');
    }

    public function testEmailEqualityComparison(): void
    {
        $email1 = Email::fromString('test@example.com');
        $email2 = Email::fromString('test@example.com');
        $email3 = Email::fromString('other@example.com');

        $this->assertTrue($email1->equals($email2));
        $this->assertFalse($email1->equals($email3));
    }

    public function testGetDomainExtractsCorrectDomain(): void
    {
        $email = Email::fromString('user@example.com');
        $this->assertSame('example.com', $email->getDomain());
    }

    public function testGetLocalPartExtractsCorrectLocalPart(): void
    {
        $email = Email::fromString('user@example.com');
        $this->assertSame('user', $email->getLocalPart());
    }
}
