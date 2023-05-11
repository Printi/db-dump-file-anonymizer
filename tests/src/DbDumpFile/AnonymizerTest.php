<?php

namespace Tests\Printi\DbDumpFile;

use PHPUnit\Framework\TestCase;
use Printi\DbDumpFile\Anonymizer;
use Printi\DbDumpFile\Anonymizer\Config;

#[CoversClass(Printi\DbDumpFile\Anonymizer::class)]
final class AnonymizerTest extends TestCase
{
    private static array $tempFiles = [];

    // Auxiliary methods

    private static function openFile(string $mode, bool $existingFile = true): mixed
    {
        $file = tempnam(sys_get_temp_dir(), 'test');
        if (!$existingFile) {
            unlink($file);
        }
        $record = [
            'file' => $file,
            'mode' => $mode,
            'stream' => fopen($file, $mode),
        ];
        self::$tempFiles[] = $record;

        return $record['stream'];
    }

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

    static public function providerValidInputFile(): iterable
    {
        yield 'tmpfile' => [tmpfile()];

        yield 'url with mode "r"' => [fopen('https://github.com/printi/db-dump-file-anonymizer', 'r')];

        yield 'file opened with mode "r"' => [self::openFile('r')];
        yield 'file opened with mode "r+"' => [self::openFile('r+')];
        yield 'file opened with mode "w+"' => [self::openFile('w+')];
        yield 'file opened with mode "a+"' => [self::openFile('a+')];
        yield 'file opened with mode "x+"' => [self::openFile('x+', false)];
        yield 'file opened with mode "c+"' => [self::openFile('c+')];

        // Binary
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

    static public function providerValidOutputFile(): iterable
    {
        yield 'file opened with mode "r+"' => [self::openFile('r+')];
        yield 'file opened with mode "w"' => [self::openFile('w')];
        yield 'file opened with mode "w+"' => [self::openFile('w+')];
        yield 'file opened with mode "a"' => [self::openFile('a')];
        yield 'file opened with mode "a+"' => [self::openFile('a+')];
        yield 'file opened with mode "x+"' => [self::openFile('x+', false)];
        yield 'file opened with mode "x"' => [self::openFile('x', false)];
        yield 'file opened with mode "c"' => [self::openFile('c')];
        yield 'file opened with mode "c+"' => [self::openFile('c+')];

        // Binary
        yield 'file opened with mode "r+b"' => [self::openFile('r+b')];
        yield 'file opened with mode "wb"' => [self::openFile('wb')];
        yield 'file opened with mode "w+b"' => [self::openFile('w+b')];
        yield 'file opened with mode "ab"' => [self::openFile('ab')];
        yield 'file opened with mode "a+b"' => [self::openFile('a+b')];
        yield 'file opened with mode "x+b"' => [self::openFile('x+b', false)];
        yield 'file opened with mode "xb"' => [self::openFile('xb', false)];
        yield 'file opened with mode "cb"' => [self::openFile('cb')];
        yield 'file opened with mode "c+b"' => [self::openFile('c+b')];

        yield 'php://stdout with mode "w"' => [fopen('php://stdout', 'w')];
        yield 'php://stderr with mode "w"' => [fopen('php://stderr', 'w')];
        yield 'STDOUT' => [STDOUT];
        yield 'STDERR' => [STDERR];
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

    static public function providerInvalidInputFiles(): iterable
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

    static public function providerInvalidOutputFiles(): iterable
    {
        yield from self::providerInvalidResources();

        yield 'file opened with mode "r"' => [self::openFile('r')];
        yield 'file opened with mode "rb"' => [self::openFile('rb')];

        yield 'php://stdin with mode "r"' => [fopen('php://stdin', 'r')];
        yield 'php://stdout with mode "r"' => [fopen('php://stdout', 'r')];
        yield 'php://stderr with mode "r"' => [fopen('php://stderr', 'r')];
        yield 'php://stdin with mode "rb"' => [fopen('php://stdin', 'rb')];
        yield 'php://stdout with mode "rb"' => [fopen('php://stdout', 'rb')];
        yield 'php://stderr with mode "rb"' => [fopen('php://stderr', 'rb')];

        yield 'STDIN' => [STDIN];
    }

    // Tests

    /**
     * @testdox Constructor setting a valid input file handler should work
     * @covers Anonymizer::__construct
     * @dataProvider providerValidInputFile
     */
    public function testConstructorSettingValidInputFileHandlerShouldWork($input): void
    {
        // Prepare
        $validOutput = STDOUT;
        $validConfig = new Config([]);

        // Execute
        $obj = new Anonymizer($input, $validOutput, $validConfig);

        // Expect
        $this->assertInstanceOf(Anonymizer::class, $obj);
    }

    /**
     * @testdox Constructor setting a valid output file handler should work
     * @covers Anonymizer::__construct
     * @dataProvider providerValidOutputFile
     */
    public function testConstructorSettingValidOutputFileHandlerShouldWork($output): void
    {
        // Prepare
        $validInput = STDIN;
        $validConfig = new Config([]);

        // Execute
        $obj = new Anonymizer($validInput, $output, $validConfig);

        // Expect
        $this->assertInstanceOf(Anonymizer::class, $obj);
    }

    /**
     * @testdox Constructor setting an invalid input file handler should break
     * @covers Anonymizer::__construct
     * @dataProvider providerInvalidInputFiles
     */
    public function testConstructorSetingInvalidInputFileHandlerShouldBreak($input)
    {
        // Prepare
        $validOutput = STDOUT;
        $validConfig = new Config([]);

        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Execute
        new Anonymizer($input, $validOutput, $validConfig);
    }

    /**
     * @testdox Constructor setting an invalid output file handler should break
     * @covers Anonymizer::__construct
     * @dataProvider providerInvalidOutputFiles
     */
    public function testConstructorSetingInvalidOutputFileHandlerShouldBreak($output)
    {
        // Prepare
        $validInput = STDIN;
        $validConfig = new Config([]);

        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Execute
        new Anonymizer($validInput, $output, $validConfig);
    }
}