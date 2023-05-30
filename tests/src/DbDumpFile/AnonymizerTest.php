<?php

namespace Tests\Printi\DbDumpFile;

use Tests\Printi\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Printi\DbDumpFile\Anonymizer;
use Printi\DbDumpFile\Anonymizer\Config;
use Printi\DbDumpFile\Parser;

#[CoversClass(Anonymizer::class)]
#[UsesClass(Config::class)]
#[UsesClass(Parser::class)]
final class AnonymizerTest extends TestCase
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

    static public function providerValidInputStreams(): iterable
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

    static public function providerValidOutputStreams(): iterable
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
        yield 'php://stdout with mode "wb"' => [fopen('php://stdout', 'wb')];
        yield 'php://stderr with mode "wb"' => [fopen('php://stderr', 'wb')];
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

    static public function providerInvalidOutputStreams(): iterable
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

    static public function providerValidInputStreamsForExecute(): iterable
    {
        yield 'empty file' => [
            'input' => self::openFile('r+', true, ''),
            'config' => new Config(['quiet' => true]),
            'expectedOutputContent' => '',
        ];

        yield 'file without inserts' => [
            'input' => self::openFile('r+', true, 'foo'),
            'config' => new Config(['quiet' => true]),
            'expectedOutputContent' => 'foo',
        ];

        yield 'simple file with insert' => [
            'input' => self::openFile('r+', true, 'INSERT INTO `foo` VALUES (1);'),
            'config' => new Config([
                'quiet' => true,
                'modifications_spec' => [
                    'foo' => [
                        '1' => [
                            'quote' => false,
                            'format' => 'passthrough',
                            'args' => ['2'],
                        ],
                    ],
                ],
            ]),
            'expectedOutputContent' => 'INSERT INTO `foo` VALUES (2);',
        ];

        $inputContent = <<<'EOF'
-- Some comment
INSERT INTO `foo` VALUES ('a\'b', 1, -1, 1.1, 1e1, NULL, NULL, 'x\'y'), ('c', 1000, -1000, 1000.1, 1000e1, NULL, NULL, 'z'),
('', 1000, -1000, 1000.1, 1000e1, '', '', '');

INSERT INTO `bar` VALUES (1);

INSERT INTO `baz``baz` VALUES (1);

insert into `foo` values ('a\'b', 1, -1, 1.1, 1e1, NULL, NULL, 'x\'y'), ('c', 1000, -1000, 1000.1, 1000e1, NULL, NULL, 'z');
EOF;
        $expectedOutputContent = <<<'EOF'
-- Some comment
INSERT INTO `foo` VALUES ('new value',2,-2,2.2,2e2,NULL,NULL,'x\'y'), ('new value',2,-2,2.2,2e2,NULL,NULL,'z'),
('new value',2,-2,2.2,2e2,'other value',NULL,'');

INSERT INTO `bar` VALUES (1);

INSERT INTO `baz``baz` VALUES (1);

insert into `foo` values ('new value',2,-2,2.2,2e2,NULL,NULL,'x\'y'), ('new value',2,-2,2.2,2e2,NULL,NULL,'z');
EOF;
        yield 'complex file with inserts' => [
            'input' => self::openFile('r+', true, $inputContent),
            'config' => new Config([
                'quiet' => true,
                'modifications_spec' => [
                    'foo' => [
                        '1' => [
                            'quote' => true,
                            'format' => 'passthrough',
                            'args' => ['new value'],
                        ],
                        '2' => [
                            'quote' => false,
                            'format' => 'passthrough',
                            'args' => ['2'],
                        ],
                        '3' => [
                            'quote' => false,
                            'format' => 'passthrough',
                            'args' => ['-2'],
                        ],
                        '4' => [
                            'quote' => false,
                            'format' => 'passthrough',
                            'args' => ['2.2'],
                        ],
                        '5' => [
                            'quote' => false,
                            'format' => 'passthrough',
                            'args' => ['2e2'],
                        ],
                        '6' => [
                            'quote' => true,
                            'format' => 'passthrough',
                            'args' => ['other value'],
                        ],
                        '7' => [
                            'quote' => false,
                            'format' => 'passthrough',
                            'args' => [null],
                        ],
                    ],
                ],
            ]),
            'expectedOutputContent' => $expectedOutputContent,
        ];
        unset($fileContent, $expectedOutputContent);
    }

    // Tests

    /**
     * @testdox Constructor setting a valid input stream should work
     * @dataProvider providerValidInputStreams
     */
    public function testConstructorSettingValidInputStreamShouldWork($input): void
    {
        // Prepare
        $validOutput = STDOUT;
        $validConfig = new Config();

        // Execute
        $obj = new Anonymizer($input, $validOutput, $validConfig);

        // Expect
        $this->assertInstanceOf(Anonymizer::class, $obj);
    }

    /**
     * @testdox Constructor setting a valid output stream should work
     * @dataProvider providerValidOutputStreams
     */
    public function testConstructorSettingValidOutputStreamShouldWork($output): void
    {
        // Prepare
        $validInput = STDIN;
        $validConfig = new Config();

        // Execute
        $obj = new Anonymizer($validInput, $output, $validConfig);

        // Expect
        $this->assertInstanceOf(Anonymizer::class, $obj);
    }

    /**
     * @testdox Constructor setting an invalid input stream should break
     * @dataProvider providerInvalidInputStreams
     */
    public function testConstructorSetingInvalidInputStreamShouldBreak($input)
    {
        // Prepare
        $validOutput = STDOUT;
        $validConfig = new Config();

        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Execute
        new Anonymizer($input, $validOutput, $validConfig);
    }

    /**
     * @testdox Constructor setting an invalid output stream should break
     * @dataProvider providerInvalidOutputStreams
     */
    public function testConstructorSetingInvalidOutputStreamsShouldBreak($output)
    {
        // Prepare
        $validInput = STDIN;
        $validConfig = new Config();

        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Execute
        new Anonymizer($validInput, $output, $validConfig);
    }

    /**
     * @testdox Executing the anonymization with a valid input file should work
     * @dataProvider providerValidInputStreamsForExecute
     */
    public function testExecuteWithValidInputShouldWork($input, $config, $expectedOutputContent)
    {
        // Prepare
        $output = self::openFile('r+');
        $anonymizer = new Anonymizer($input, $output, $config);

        // Execute
        $anonymizer->execute();

        // Expect
        $size = ftell($output);
        fseek($output, 0, SEEK_SET);
        $actualOutputContent = fread($output, $size + 1);

        $this->assertSame($expectedOutputContent, $actualOutputContent);
    }

    /**
     * @testdox Executing the anonymization with small  buffers should work
     */
    public function testExecuteWithSmallBuffersShouldWork()
    {
        // Prepare
        $input = self::openFile('r+', true, 'INSERT INTO `foo` VALUES (1);');
        $output = self::openFile('r+');
        $config = new Config([
            'read_buffer_size' => 1,
            'write_buffer_size' => 1,
            'quiet' => true,
            'modifications_spec' => [
                'foo' => [
                    '1' => [
                        'quote' => false,
                        'format' => 'passthrough',
                        'args' => ['2'],
                    ],
                ],
            ],
        ]);
        $anonymizer = new Anonymizer($input, $output, $config);

        // Execute
        $anonymizer->execute();

        // Expect
        $size = ftell($output);
        fseek($output, 0, SEEK_SET);
        $actualOutputContent = fread($output, $size + 1);

        $expectedOutputContent = 'INSERT INTO `foo` VALUES (2);';

        $this->assertSame($expectedOutputContent, $actualOutputContent);
    }

    /**
     * @testdox Executing the anonymization with a column modification spec using unique should produce unique values
     */
    public function testExecuteWithModificationSpecIncludingUniqueColumn()
    {
        // Prepare
        $inputContent = 'INSERT INTO `foo` VALUES (0);INSERT INTO `foo` VALUES (0);';
        $input = self::openFile('r+', true, $inputContent);
        $config = new Config([
            'quiet' => true,
            'faker_seed' => 1, // This seed forces the random values of Faker to be always the same
            'modifications_spec' => [
                'foo' => [
                    '1' => [
                        'quote' => false,
                        'format' => 'numberBetween',
                        'args' => [1, 2],
                        'unique' => true,
                    ],
                ],
            ],
        ]);
        $output = self::openFile('r+');
        $anonymizer = new Anonymizer($input, $output, $config);

        // Execute
        $anonymizer->execute();

        // Expect
        $size = ftell($output);
        fseek($output, 0, SEEK_SET);
        $actualOutputContent = fread($output, $size + 1);

        $regex = '/^INSERT INTO `foo` VALUES \(2\);INSERT INTO `foo` VALUES \(1\);$/';
        $this->assertMatchesRegularExpression($regex, $actualOutputContent);
    }

    /**
     * @testdox Executing the anonymization with a column modification spec using optional (weight=0%) should produce optional values
     */
    public function testExecuteWithModificationSpecIncludingOptionalColumn()
    {
        // Prepare
        $inputContent = 'INSERT INTO `foo` VALUES (0);INSERT INTO `foo` VALUES (0);';
        $input = self::openFile('r+', true, $inputContent);
        $config = new Config([
            'quiet' => true,
            'modifications_spec' => [
                'foo' => [
                    '1' => [
                        'quote' => false,
                        'format' => 'passthrough',
                        'args' => [1],
                        'optional' => true,
                        'optional_weight' => 0.0,
                    ],
                ],
            ],
        ]);
        $output = self::openFile('r+');
        $anonymizer = new Anonymizer($input, $output, $config);

        // Execute
        $anonymizer->execute();

        // Expect
        $size = ftell($output);
        fseek($output, 0, SEEK_SET);
        $actualOutputContent = fread($output, $size + 1);

        $regex = '/^INSERT INTO `foo` VALUES \(NULL\);INSERT INTO `foo` VALUES \(NULL\);$/';
        $this->assertMatchesRegularExpression($regex, $actualOutputContent);
    }

    /**
     * @testdox Executing the anonymization with a column modification spec using optional (weight=100%) should not produce optional values
     */
    public function testExecuteWithModificationSpecIncludingOptionalColumnWithWeight1()
    {
        // Prepare
        $inputContent = 'INSERT INTO `foo` VALUES (0);INSERT INTO `foo` VALUES (0);';
        $input = self::openFile('r+', true, $inputContent);
        $config = new Config([
            'quiet' => true,
            'modifications_spec' => [
                'foo' => [
                    '1' => [
                        'quote' => false,
                        'format' => 'passthrough',
                        'args' => [1],
                        'optional' => true,
                        'optional_weight' => 1.0,
                    ],
                ],
            ],
        ]);
        $output = self::openFile('r+');
        $anonymizer = new Anonymizer($input, $output, $config);

        // Execute
        $anonymizer->execute();

        // Expect
        $size = ftell($output);
        fseek($output, 0, SEEK_SET);
        $actualOutputContent = fread($output, $size + 1);

        $regex = '/^INSERT INTO `foo` VALUES \(1\);INSERT INTO `foo` VALUES \(1\);$/';
        $this->assertMatchesRegularExpression($regex, $actualOutputContent);
    }

    /**
     * @testdox Executing the anonymization with a column modification spec using optional (weight=0%), with default value, should produce optional values
     */
    public function testExecuteWithModificationSpecIncludingOptionalColumnWithDefaultValue()
    {
        // Prepare
        $inputContent = 'INSERT INTO `foo` VALUES (0);INSERT INTO `foo` VALUES (0);';
        $input = self::openFile('r+', true, $inputContent);
        $config = new Config([
            'quiet' => true,
            'modifications_spec' => [
                'foo' => [
                    '1' => [
                        'quote' => false,
                        'format' => 'passthrough',
                        'args' => ['1'],
                        'optional' => true,
                        'optional_weight' => 0.0,
                        'optional_default_value' => '2',
                    ],
                ],
            ],
        ]);
        $output = self::openFile('r+');
        $anonymizer = new Anonymizer($input, $output, $config);

        // Execute
        $anonymizer->execute();

        // Expect
        $size = ftell($output);
        fseek($output, 0, SEEK_SET);
        $actualOutputContent = fread($output, $size + 1);

        $regex = '/^INSERT INTO `foo` VALUES \(2\);INSERT INTO `foo` VALUES \(2\);$/';
        $this->assertMatchesRegularExpression($regex, $actualOutputContent);
    }
}
