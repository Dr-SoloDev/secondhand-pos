<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class SellerIdCipherTest extends TestCase
{
    private string|false $originalKey;
    private string|false $originalEnvironment;

    protected function setUp(): void
    {
        $this->originalKey = getenv('SELLER_ID_ENCRYPTION_KEY');
        $this->originalEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=testing');
        putenv('SELLER_ID_ENCRYPTION_KEY=unit-test-seller-id-key-material-0123456789abcdef');
        \SellerIdCipher::resetForTests();
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment('SELLER_ID_ENCRYPTION_KEY', $this->originalKey);
        $this->restoreEnvironment('APP_ENV', $this->originalEnvironment);
        \SellerIdCipher::resetForTests();
    }

    public function testEncryptsWithRandomNonceAndDecrypts(): void
    {
        $idCard = '1101700207036';
        $first = \SellerIdCipher::encrypt($idCard);
        $second = \SellerIdCipher::encrypt($idCard);

        self::assertStringStartsWith('v1.', $first);
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString($idCard, $first);
        self::assertSame($idCard, \SellerIdCipher::decrypt($first));
        self::assertSame($idCard, \SellerIdCipher::decrypt($second));
    }

    public function testSearchHashIsDeterministicAndKeyed(): void
    {
        $idCard = '1101700207036';
        $first = \SellerIdCipher::searchHash($idCard);
        self::assertSame($first, \SellerIdCipher::searchHash($idCard));

        putenv('SELLER_ID_ENCRYPTION_KEY=another-unit-test-key-material-abcdef0123456789');
        \SellerIdCipher::resetForTests();
        self::assertNotSame($first, \SellerIdCipher::searchHash($idCard));
    }

    public function testWrongKeyCannotDecryptCiphertext(): void
    {
        $ciphertext = \SellerIdCipher::encrypt('1101700207036');
        putenv('SELLER_ID_ENCRYPTION_KEY=wrong-unit-test-key-material-0123456789abcdef');
        \SellerIdCipher::resetForTests();

        $this->expectException(RuntimeException::class);
        \SellerIdCipher::decrypt($ciphertext);
    }

    public function testProductionRequiresDedicatedKey(): void
    {
        putenv('APP_ENV=production');
        putenv('SELLER_ID_ENCRYPTION_KEY');
        \SellerIdCipher::resetForTests();

        $this->expectException(RuntimeException::class);
        \SellerIdCipher::searchHash('1101700207036');
    }

    private function restoreEnvironment(string $name, string|false $value): void
    {
        putenv($value === false ? $name : $name . '=' . $value);
    }
}
