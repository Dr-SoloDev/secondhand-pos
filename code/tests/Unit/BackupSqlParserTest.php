<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BackupSqlParserTest extends TestCase
{
    public function testHandlesMysqldumpDelimiterAfterComment(): void
    {
        $sql = <<<'SQL'
-- regular statement before a routine
CREATE TABLE demo (id INT, note VARCHAR(100));
-- mysqldump emits comments before changing the client delimiter
DELIMITER $$
CREATE PROCEDURE add_demo()
BEGIN
  INSERT INTO demo (id, note) VALUES (1, 'semi;colon');
  UPDATE demo SET note = 'done' WHERE id = 1;
END$$
DELIMITER ;
INSERT INTO demo (id, note) VALUES (2, 'tail');
SQL;

        $method = new ReflectionMethod(\BackupService::class, 'splitSqlStatements');
        $statements = $method->invoke(null, $sql);

        self::assertCount(3, $statements);
        self::assertStringContainsString('CREATE TABLE demo', $statements[0]);
        self::assertStringContainsString('CREATE PROCEDURE add_demo()', $statements[1]);
        self::assertStringContainsString("'semi;colon'", $statements[1]);
        self::assertStringContainsString("UPDATE demo SET note = 'done'", $statements[1]);
        self::assertStringNotContainsString('DELIMITER', implode("\n", $statements));
        self::assertStringContainsString("VALUES (2, 'tail')", $statements[2]);
    }
}
