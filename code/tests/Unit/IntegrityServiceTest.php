<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * IntegrityService — canonical JSON + chain hash (pure functions ไม่แตะ DB)
 * DB parts (append/verify) ตรวจผ่าน E2E ใน scripts/integrity-backfill.php
 */
class IntegrityServiceTest extends TestCase
{
    private string|false $originalKey;
    private string|false $originalEnv;

    protected function setUp(): void
    {
        $this->originalKey = getenv('SELLER_ID_ENCRYPTION_KEY');
        $this->originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=testing');
        putenv('SELLER_ID_ENCRYPTION_KEY=unit-test-seller-id-key-material-0123456789abcdef');
        \IntegrityService::resetForTests();
    }

    protected function tearDown(): void
    {
        putenv($this->originalKey === false ? 'SELLER_ID_ENCRYPTION_KEY' : 'SELLER_ID_ENCRYPTION_KEY=' . $this->originalKey);
        putenv($this->originalEnv === false ? 'APP_ENV' : 'APP_ENV=' . $this->originalEnv);
        \IntegrityService::resetForTests();
    }

    public function testCanonicalJsonSortsKeys(): void
    {
        $a = \IntegrityService::canonicalJson(['b' => 1, 'a' => 2]);
        $b = \IntegrityService::canonicalJson(['a' => 2, 'b' => 1]);
        self::assertSame($a, $b);
        self::assertSame('{"a":2,"b":1}', $a);
    }

    public function testCanonicalJsonNormalizesMoney(): void
    {
        self::assertSame('{"amount":1234.50}', \IntegrityService::canonicalJson(['amount' => 1234.5]));
        self::assertSame('{"amount":1234.50}', \IntegrityService::canonicalJson(['amount' => 1234.5000001]));
    }

    public function testCanonicalJsonHandlesNestedAndUnicode(): void
    {
        $json = \IntegrityService::canonicalJson([
            'name' => 'สมชาย',
            'tags' => ['x', 'y'],
            'nested' => ['z' => null, 'flag' => true],
        ]);
        self::assertSame(
            '{"name":"สมชาย","nested":{"flag":true,"z":null},"tags":["x","y"]}',
            $json
        );
    }

    public function testPayloadHashDeterministic(): void
    {
        $h1 = \IntegrityService::payloadHash(['full_name' => 'A', 'phone' => '0812345678']);
        $h2 = \IntegrityService::payloadHash(['phone' => '0812345678', 'full_name' => 'A']);
        self::assertSame($h1, $h2);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h1);
    }

    public function testChainHashLinksPrevHash(): void
    {
        $gen1 = \IntegrityService::computeChainHash(
            \IntegrityService::GENESIS, 'aa', 'create', 1, 'seller', 1
        );
        $gen2 = \IntegrityService::computeChainHash(
            \IntegrityService::GENESIS, 'aa', 'create', 1, 'seller', 1
        );
        self::assertSame($gen1, $gen2); // deterministic

        // prev ต่าง → hash ต่าง (chain link)
        $link = \IntegrityService::computeChainHash($gen1, 'bb', 'update', 2, 'seller', 1);
        self::assertNotSame($gen1, $link);

        // payload ต่าง → hash ต่าง (แก้ payload จับได้)
        $tampered = \IntegrityService::computeChainHash(
            \IntegrityService::GENESIS, 'ab', 'create', 1, 'seller', 1
        );
        self::assertNotSame($gen1, $tampered);

        // entity ต่าง → hash ต่าง (ข้าม chain จับได้)
        $other = \IntegrityService::computeChainHash(
            \IntegrityService::GENESIS, 'aa', 'create', 1, 'seller', 2
        );
        self::assertNotSame($gen1, $other);
    }

    public function testGenesisConstant(): void
    {
        self::assertSame(64, strlen(\IntegrityService::GENESIS));
        self::assertSame('0000000000000000000000000000000000000000000000000000000000000000', \IntegrityService::GENESIS);
    }
}
