<?php

namespace Tests\Printi\DbDumpFile;

use Tests\Printi\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Printi\DbDumpFile\Parser;

#[CoversClass(Parser::class)]
final class ParserTest extends TestCase
{
    private static array $tempFiles = [];

    // Helper methods

    private static function openFile(string $mode, bool $existingFile = true, string $content = ''): mixed
    {
        $fileRecord = Fixtures::openFile($mode, $existingFile, $content);

        self::$tempFiles[] = $fileRecord;

        return $fileRecord['stream'];
    }

    // Hook methods

    /**
     * @inheritdoc
     */
    public static function tearDownAfterClass(): void
    {
        foreach (self::$tempFiles as $file) {
            if (is_resource($file['stream'])) {
                fclose($file['stream']);
            }
            if (is_file($file['file'])) {
                unlink($file['file']);
            }
        }
    }

    // Providers

    public static function providerValidInputStreams(): iterable
    {
        yield 'tmpfile' => [tmpfile()];

        yield 'file opened with mode "r"' => [self::openFile('r')];
        yield 'file opened with mode "r+"' => [self::openFile('r+')];
        yield 'file opened with mode "w+"' => [self::openFile('w+')];
        yield 'file opened with mode "a+"' => [self::openFile('a+')];
        yield 'file opened with mode "x+"' => [self::openFile('x+', false)];
        yield 'file opened with mode "c+"' => [self::openFile('c+')];

        yield 'file opened with mode "rb"' => [self::openFile('rb')];
        yield 'file opened with mode "r+b"' => [self::openFile('r+b')];
        yield 'file opened with mode "w+b"' => [self::openFile('w+b')];
        yield 'file opened with mode "a+b"' => [self::openFile('a+b')];
        yield 'file opened with mode "x+b"' => [self::openFile('x+b', false)];
        yield 'file opened with mode "c+b"' => [self::openFile('c+b')];

        yield 'STDIN' => [STDIN];
        yield 'php://stdin with mode "r"' => [fopen('php://stdin', 'r')];
        yield 'php://stdin with mode "rb"' => [fopen('php://stdin', 'rb')];
    }

    static public function providerInvalidResources(): iterable
    {
        yield 'null' => [null];
        yield 'string' => [''];
        yield 'integer' => [0];
        yield 'float' => [0.0];
        yield 'boolean' => [true];
        yield 'array' => [[]];
        yield 'object' => [new \stdClass()];
    }

    static public function providerInvalidInputStreams(): iterable
    {
        yield from self::providerInvalidResources();

        yield 'file opened with mode "w"' => [self::openFile('w')];
        yield 'file opened with mode "a"' => [self::openFile('a')];
        yield 'file opened with mode "x"' => [self::openFile('x', false)];
        yield 'file opened with mode "c"' => [self::openFile('c')];
        yield 'file opened with mode "wb"' => [self::openFile('wb')];
        yield 'file opened with mode "ab"' => [self::openFile('ab')];
        yield 'file opened with mode "xb"' => [self::openFile('xb', false)];
        yield 'file opened with mode "cb"' => [self::openFile('cb')];

        yield 'php://stdout with mode "w"' => [fopen('php://stdout', 'w')];
        yield 'php://stderr with mode "w"' => [fopen('php://stderr', 'w')];
        yield 'php://stdout with mode "wb"' => [fopen('php://stdout', 'wb')];
        yield 'php://stderr with mode "wb"' => [fopen('php://stderr', 'wb')];

        yield 'STDOUT' => [STDOUT];
        yield 'STDERR' => [STDERR];
    }

    // Tests

    /**
     * @testdox Constructor setting a valid input stream should work
     * @dataProvider providerValidInputStreams
     */
    public function testConstructorSettingValidInputStreamShouldWork($input): void
    {
        // Prepare
        $validConfig = [];

        // Execute
        $obj = new Parser($input, $validConfig);

        // Expect
        $this->assertInstanceOf(Parser::class, $obj);
    }

    /**
     * @testdox Constructor setting an invalid input stream should break
     * @dataProvider providerInvalidInputStreams
     */
    public function testConstructorSetingInvalidInputStreamShouldBreak($input)
    {
        // Prepare
        $validConfig = [];

        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Execute
        new Parser($input, $validConfig);
    }

    /**
     * @testdox Testing the main parser method should work
     */
    public function testParseTokens()
    {
        // Prepare
        $fileContent = <<<EOF
-- Some comment
INSERT INTO `foo` VALUES ('foo', 1, -1, 1.1, 1e1, NULL), ('foo', 2, -2, 2.2, 2e2, NULL),
('foo', 3, -3, 3.3, 3e3, NULL);

INSERT INTO `bar` VALUES ('bar', 1, -1, 1.1, 1e1, NULL), ('bar', 2, -2, 2.2, 2e2, NULL),
('bar', 3, -3, 3.3, 3e3, NULL);

INSERT INTO `baz` VALUES ('baz', 1, -1, 1.1, 1e1, NULL), ('baz', 2, -2, 2.2, 2e2, NULL),
('baz', 3, -3, 3.3, 3e3, NULL);

-- Other comment
EOF;

        $input = self::openFile('r+', true, $fileContent);
        $config = ['tables' => ['foo', 'bar']];
        $parser = new Parser($input, $config);

        // Execute
        $iterator = $parser->parseTokens();

        // Expect
        $this->assertInstanceOf(\Iterator::class, $iterator);

        $expectedTokens = [
            ['type' => Parser::TYPE_CHUNK_TOKEN, 'raw' => "-- Some comment\n"],
            ['type' => Parser::TYPE_INSERT_START_TOKEN, 'raw' => 'INSERT INTO `foo` VALUES ', 'table' => 'foo'],
            [
                'type' => Parser::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => "('foo', 1, -1, 1.1, 1e1, NULL)",
                'values' => [
                    1 => ['type' => 'string', 'raw' => "'foo'"],
                    2 => ['type' => 'number', 'raw' => '1'],
                    3 => ['type' => 'number', 'raw' => '-1'],
                    4 => ['type' => 'number', 'raw' => '1.1'],
                    5 => ['type' => 'number', 'raw' => '1e1'],
                    6 => ['type' => 'null', 'raw' => 'NULL'],
                ],
            ],
            ['type' => Parser::TYPE_INSERT_TUPLE_SEPARATOR_TOKEN, 'raw' => ', '],
            [
                'type' => Parser::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => "('foo', 2, -2, 2.2, 2e2, NULL)",
                'values' => [
                    1 => ['type' => 'string', 'raw' => "'foo'"],
                    2 => ['type' => 'number', 'raw' => '2'],
                    3 => ['type' => 'number', 'raw' => '-2'],
                    4 => ['type' => 'number', 'raw' => '2.2'],
                    5 => ['type' => 'number', 'raw' => '2e2'],
                    6 => ['type' => 'null', 'raw' => 'NULL'],
                ],
            ],
            ['type' => Parser::TYPE_INSERT_TUPLE_SEPARATOR_TOKEN, 'raw' => ",\n"],
            [
                'type' => Parser::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => "('foo', 3, -3, 3.3, 3e3, NULL)",
                'values' => [
                    1 => ['type' => 'string', 'raw' => "'foo'"],
                    2 => ['type' => 'number', 'raw' => '3'],
                    3 => ['type' => 'number', 'raw' => '-3'],
                    4 => ['type' => 'number', 'raw' => '3.3'],
                    5 => ['type' => 'number', 'raw' => '3e3'],
                    6 => ['type' => 'null', 'raw' => 'NULL'],
                ],
            ],
            ['type' => Parser::TYPE_INSERT_END_TOKEN, 'raw' => ";\n\n"],
            ['type' => Parser::TYPE_INSERT_START_TOKEN, 'raw' => 'INSERT INTO `bar` VALUES ', 'table' => 'bar'],
            [
                'type' => Parser::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => "('bar', 1, -1, 1.1, 1e1, NULL)",
                'values' => [
                    1 => ['type' => 'string', 'raw' => "'bar'"],
                    2 => ['type' => 'number', 'raw' => '1'],
                    3 => ['type' => 'number', 'raw' => '-1'],
                    4 => ['type' => 'number', 'raw' => '1.1'],
                    5 => ['type' => 'number', 'raw' => '1e1'],
                    6 => ['type' => 'null', 'raw' => 'NULL'],
                ],
            ],
            ['type' => Parser::TYPE_INSERT_TUPLE_SEPARATOR_TOKEN, 'raw' => ', '],
            [
                'type' => Parser::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => "('bar', 2, -2, 2.2, 2e2, NULL)",
                'values' => [
                    1 => ['type' => 'string', 'raw' => "'bar'"],
                    2 => ['type' => 'number', 'raw' => '2'],
                    3 => ['type' => 'number', 'raw' => '-2'],
                    4 => ['type' => 'number', 'raw' => '2.2'],
                    5 => ['type' => 'number', 'raw' => '2e2'],
                    6 => ['type' => 'null', 'raw' => 'NULL'],
                ],
            ],
            ['type' => Parser::TYPE_INSERT_TUPLE_SEPARATOR_TOKEN, 'raw' => ",\n"],
            [
                'type' => Parser::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => "('bar', 3, -3, 3.3, 3e3, NULL)",
                'values' => [
                    1 => ['type' => 'string', 'raw' => "'bar'"],
                    2 => ['type' => 'number', 'raw' => '3'],
                    3 => ['type' => 'number', 'raw' => '-3'],
                    4 => ['type' => 'number', 'raw' => '3.3'],
                    5 => ['type' => 'number', 'raw' => '3e3'],
                    6 => ['type' => 'null', 'raw' => 'NULL'],
                ],
            ],
            ['type' => Parser::TYPE_INSERT_END_TOKEN, 'raw' => ";\n\n"],
            ['type' => Parser::TYPE_CHUNK_TOKEN, 'raw' => "INSERT INTO `baz` VALUES ('baz', 1, -1, 1.1, 1e1, NULL), ('baz', 2, -2, 2.2, 2e2, NULL),\n"],
            ['type' => Parser::TYPE_CHUNK_TOKEN, 'raw' => "('baz', 3, -3, 3.3, 3e3, NULL);\n"],
            ['type' => Parser::TYPE_CHUNK_TOKEN, 'raw' => "\n"],
            ['type' => Parser::TYPE_CHUNK_TOKEN, 'raw' => '-- Other comment'],
        ];
        $this->assertSame($expectedTokens, iterator_to_array($iterator));
    }
}
