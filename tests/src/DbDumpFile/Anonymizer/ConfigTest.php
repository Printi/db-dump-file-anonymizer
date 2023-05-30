<?php

namespace Tests\Printi\DbDumpFile\Anonymizer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Printi\DbDumpFile\Anonymizer\Config;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    // Providers

    public static function providerValidOptions(): iterable
    {
        // locale
        yield 'locale null' => [['locale' => null]];
        yield 'locale en_US' => [['locale' => 'en_US']];

        // quiet
        yield 'quiet null' => [['quiet' => null]];
        yield 'quiet true' => [['quiet' => true]];
        yield 'quiet false' => [['quiet' => false]];

        // faker_seed
        yield 'faker_seed null' => [['faker_seed' => null]];
        yield 'faker_seed 1' => [['faker_seed' => 1]];

        // notification_stream
        yield 'notification_stream null' => [['notification_stream' => null]];
        yield 'notification_stream STDOUT' => [['notification_stream' => STDOUT]];

        // read_buffer_size
        yield 'read_buffer_size null' => [['read_buffer_size' => null]];
        yield 'read_buffer_size 1' => [['read_buffer_size' => 1]];

        // write_buffer_size
        yield 'write_buffer_size null' => [['write_buffer_size' => null]];
        yield 'write_buffer_size 1' => [['write_buffer_size' => 1]];

        // reserve_output_file_size
        yield 'reserve_output_file_size null' => [['reserve_output_file_size' => null]];
        yield 'reserve_output_file_size 1' => [['reserve_output_file_size' => 1]];

        // modifications_spec
        yield 'modifications_spec #0' => [['modifications_spec' => null]];
        yield 'modifications_spec #1' => [['modifications_spec' => []]];
        yield 'modifications_spec #2' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify']]]]];
        yield 'modifications_spec #3' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'quote' => true]]]]];
        yield 'modifications_spec #4' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'args' => ['?']]]]]];
        yield 'modifications_spec #5' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'unique' => true]]]]];
        yield 'modifications_spec #6' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'optional' => true]]]]];
        yield 'modifications_spec #7' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'optional_weight' => 0.0]]]]];
        yield 'modifications_spec #8' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'optional_weight' => 1.0]]]]];
        yield 'modifications_spec #9' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'optional_default_value' => 1]]]]];
    }

    public static function providerInvalidOptions(): iterable
    {
        // notification_stream
        yield 'notification_stream false' => [['notification_stream' => false]];
        yield 'notification_stream 1' => [['notification_stream' => 1]];
        yield 'notification_stream 1.1' => [['notification_stream' => 1.1]];
        yield 'notification_stream "a"' => [['notification_stream' => 'a']];
        yield 'notification_stream []' => [['notification_stream' => []]];
        yield 'notification_stream object' => [['notification_stream' => new \stdClass()]];
        yield 'notification_stream STDIN' => [['notification_stream' => STDIN]];

        // read_buffer_size
        yield 'read_buffer_size 0' => [['read_buffer_size' => 0]];
        yield 'read_buffer_size false' => [['read_buffer_size' => false]];
        yield 'read_buffer_size 1.1' => [['read_buffer_size' => 1.1]];
        yield 'read_buffer_size "a"' => [['read_buffer_size' => 'a']];
        yield 'read_buffer_size []' => [['read_buffer_size' => []]];
        yield 'read_buffer_size object' => [['read_buffer_size' => new \stdClass()]];

        // write_buffer_size
        yield 'write_buffer_size 0' => [['write_buffer_size' => 0]];
        yield 'write_buffer_size false' => [['write_buffer_size' => false]];
        yield 'write_buffer_size 1.1' => [['write_buffer_size' => 1.1]];
        yield 'write_buffer_size "a"' => [['write_buffer_size' => 'a']];
        yield 'write_buffer_size []' => [['write_buffer_size' => []]];
        yield 'write_buffer_size object' => [['write_buffer_size' => new \stdClass()]];

        // reserve_output_file_size
        yield 'reserve_output_file_size 0' => [['reserve_output_file_size' => 0]];
        yield 'reserve_output_file_size false' => [['reserve_output_file_size' => false]];
        yield 'reserve_output_file_size 1.1' => [['reserve_output_file_size' => 1.1]];
        yield 'reserve_output_file_size "a"' => [['reserve_output_file_size' => 'a']];
        yield 'reserve_output_file_size []' => [['reserve_output_file_size' => []]];
        yield 'reserve_output_file_size object' => [['reserve_output_file_size' => new \stdClass()]];

        // faker_seed
        yield 'faker_seed 0' => [['faker_seed' => 0]];
        yield 'faker_seed false' => [['faker_seed' => false]];
        yield 'faker_seed 1.1' => [['faker_seed' => 1.1]];
        yield 'faker_seed "a"' => [['faker_seed' => 'a']];
        yield 'faker_seed []' => [['faker_seed' => []]];
        yield 'faker_seed object' => [['faker_seed' => new \stdClass()]];

        // modifications_spec
        yield 'modifications_spec with table 0' => [['modifications_spec' => [0 => []]]];
        yield 'modifications_spec with column number "a"' => [['modifications_spec' => ['foo' => ['a' => []]]]];
        yield 'modifications_spec with empty format' => [['modifications_spec' => ['foo' => [1 => []]]]];
        yield 'modifications_spec with format false' => [['modifications_spec' => ['foo' => [1 => ['format' => false]]]]];
        yield 'modifications_spec with quote null' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'quote' => null]]]]];
        yield 'modifications_spec with args null' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'args' => null]]]]];
        yield 'modifications_spec with unique null' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'unique' => null]]]]];
        yield 'modifications_spec with optional null' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'optional' => null]]]]];
        yield 'modifications_spec with optional_weight null' => [['modifications_spec' => ['foo' => [1 => ['format' => 'lexify', 'optional_weight' => null]]]]];
    }

    // Tests

    /**
     * @testdox Constructor setting empty options should load default values
     */
    public function testConstructorSettingEmptyOptionsShoulLoadDefaultValues(): void
    {
        // Prepare
        $options = [];

        // Execute
        $obj = new Config($options);

        // Expect
        $this->assertInstanceOf(Config::class, $obj);
        $this->assertSame(Config::DEFAULT_RESERVE_OUTPUT_FILE_SIZE, $obj->getReserveOutputFileSize());
        $this->assertSame(Config::DEFAULT_READ_BUFFER_SIZE, $obj->getReadBufferSize());
        $this->assertSame(Config::DEFAULT_WRITE_BUFFER_SIZE, $obj->getWriteBufferSize());
        $this->assertSame(Config::DEFAULT_LOCALE, $obj->getLocale());
        $this->assertSame(Config::DEFAULT_QUIET_FLAG, $obj->getQuiet());
        $this->assertSame(Config::DEFAULT_NOTIFICATION_STREAM, $obj->getNotificationStream());
        $this->assertSame([], $obj->getModificationsSpec());
        $this->assertSame(null, $obj->getFakerSeed());
    }

    /**
     * @testdox Constructor setting valid options should work
     * @dataProvider providerValidOptions
     */
    public function testConstructorSettingValidOptionsShouldWork($options): void
    {
        // Execute
        $obj = new Config($options);

        // Expect
        $this->assertInstanceOf(Config::class, $obj);
    }

    /**
     * @testdox Constructor setting invvalid options should break
     * @dataProvider providerInvalidOptions
     */
    public function testConstructorSettingInvalidOptionsShouldBreak($options): void
    {
        // Expect
        $this->expectException(\InvalidArgumentException::class);

        // Execute
        $obj = new Config($options);
    }

    /**
     * @testdox Getting a specific column modification spec
     */
    public function testGettingSpecificColumnModificationSpec(): void
    {
        // Prepare
        $columnSpec1 = [
            'quote' => true,
            'format' => 'lexify',
            'args' => [['a']],
        ];
        $columnSpec2 = [
            'quote' => true,
            'format' => 'lexify',
            'args' => [['b']],
        ];

        $options = [
            'modifications_spec' => [
                'foo' => [
                    1 => $columnSpec1,
                    2 => $columnSpec2,
                ]
            ],
        ];

        // Execute
        $obj = new Config($options);

        // Expect
        $this->assertInstanceOf(Config::class, $obj);
        $this->assertSame($columnSpec2, $obj->getColumnModificationSpec('foo', 2));
    }
}
