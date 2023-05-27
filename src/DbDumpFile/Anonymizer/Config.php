<?php

declare(strict_types=1);

namespace Printi\DbDumpFile\Anonymizer;

use PHPUnit\Framework\Attributes\CodeCoverageIgnore;

/**
 * Configuration container for the class \Printi\DbDumpFile\Anonimizer
 */
class Config
{
    public const DEFAULT_LOCALE = 'en_US';
    public const DEFAULT_QUIET_FLAG = false;
    public const DEFAULT_NOTIFICATION_STREAM = STDERR;
    public const DEFAULT_RESERVE_OUTPUT_FILE_SIZE = 1052872704; // 1GB
    public const DEFAULT_READ_BUFFER_SIZE = 512000; // 500KB
    public const DEFAULT_WRITE_BUFFER_SIZE = 512000; // 500KB

    /**
     * The file size that is used to reserve space in storage before generating the output file.
     * It is only used when the output stream is seekable, but the input stream is not.
     * For example:
     * If the input file stream is seekable, then 110% of its size is used as reservation.
     * Otherwise the value of this attribute is considered to reserve space.
     * @var int
     */
    private int $reserveOutputFileSize;

    /**
     * The amount of bytes to read from input file per time to speed up the process
     * @var int
     */
    private int $readBufferSize;

    /**
     * The amount of bytes to keep in buffer before writing into the output stream
     * @var int
     */
    private int $writeBufferSize;

    /** @var string Locale used by faker instances */
    private string $locale;

    /** @var bool Whether to ommit notifications or not */
    private bool $quiet;

    /** @var resource Output stream for notifications */
    private $notificationStream;

    /**
     * Specification of the desired modifications over the dump file
     *
     * The index of the array indicates the column position, and the value of the array
     * indicates the modification specification for that column.
     *
     * Each column spec may have these keys:
     *   bool 'quote' Whether the value must be quoted.
     *   string 'format' The Faker format
     *   array 'args' The arguments to be used in the informed format
     *   bool 'unique' Whether the column is unique
     *   bool 'optional' Whether the column is optional
     *   mixed 'optional_default_value' The default value for optional columns
     *   float 'optional_weight' The probability to chose a non-default value (1.0 = 100%, 0.0 = 0%)
     *
     * When the flag `optional` is true, Faker class will chose a default value or a
     * non-default value, according to a probability.
     *
     * Example:
     * [
     *     "customer" => [
     *         2 => ["quote" => false, "format" => "numberBetween", "args" => [1, 10]],
     *         5 => ["quote" => true, "format" => "firstName"],
     *         6 => ["quote" => true, "format" => "lastName"],
     *     ],
     * ]
     * @var array
     */
    private array $modificationsSpec;

    /**
     * Constructor
     * @param array $config Associative array with:
     *   string 'locale' Locale of the Faker instance
     *   bool 'quiet' Wheter to omit messages
     *   resource 'notification_stream' Output stream for notifications
     *   int 'read_buffer_size' Buffer size for reading the input stream
     *   int 'write_buffer_size' Buffer size for writing the output stream
     *   int 'reserve_output_file_size' Reserves the requested size for the output stream when it is seekable
     *   array 'modifications_spec' Specification of the expected modifications (see self::$modificationsSpec)
     * @throws \InvalidArgumentException
     */
    public function __construct(array $config = [])
    {
        $this->setConfig($config);
    }

    /**
     * Return the attribute reserveOutputFileSize
     * @return int
     */
    public function getReserveOutputFileSize(): int
    {
        return $this->reserveOutputFileSize;
    }

    /**
     * Return the attribute readBufferSize
     * @return int
     */
    public function getReadBufferSize(): int
    {
        return $this->readBufferSize;
    }

    /**
     * Return the attribute writeBufferSize
     * @return int
     */
    public function getWriteBufferSize(): int
    {
        return $this->writeBufferSize;
    }

    /**
     * Return the attribute locale
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Return the attribute quiet
     * @return bool
     */
    public function getQuiet(): bool
    {
        return $this->quiet;
    }

    /**
     * Return the attribute notificationStream
     * @return resource
     */
    public function getNotificationStream()
    {
        return $this->notificationStream;
    }

    /**
     * Return the attribute modificationsSpec
     * @return array
     */
    public function getModificationsSpec(): array
    {
        return $this->modificationsSpec;
    }

    /**
     * Return the modification spec for a specific column
     * @param string $table
     * @param int $columnNumber
     * @return ?array
     */
    public function getColumnModificationSpec(string $table, int $columnNumber): ?array
    {
        return $this->modificationsSpec[$table][$columnNumber] ?? null;
    }

    /**
     * Set the attributes for anonymization based on an array of config
     * @param array $config Associative array with:
     *   string 'locale' Locale of the Faker instance
     *   bool 'quiet' Wheter to omit messages
     *   resource 'notification_stream' Output stream for notifications
     *   int 'read_buffer_size' Buffer size for reading the input stream
     *   int 'reserve_output_file_size' Reserves the requested size for the output stream when it is seekable
     *   array 'modifications_spec'
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function setConfig(array $config): void
    {
        $this->reserveOutputFileSize = $this->validatePositiveInt(
            'reserve_output_file_size',
            $config['reserve_output_file_size'] ?? null,
            self::DEFAULT_RESERVE_OUTPUT_FILE_SIZE,
        );

        $this->readBufferSize = $this->validatePositiveInt(
            'read_buffer_size',
            $config['read_buffer_size'] ?? null,
            self::DEFAULT_READ_BUFFER_SIZE,
        );

        $this->writeBufferSize = $this->validatePositiveInt(
            'write_buffer_size',
            $config['write_buffer_size'] ?? null,
            self::DEFAULT_WRITE_BUFFER_SIZE,
        );

        $this->locale = strval($config['locale'] ?? self::DEFAULT_LOCALE);
        $this->quiet = boolval($config['quiet'] ?? self::DEFAULT_QUIET_FLAG);
        $this->setNotificationStream($config['notification_stream'] ?? self::DEFAULT_NOTIFICATION_STREAM);

        $this->setModificationsSpec($config['modifications_spec'] ?? []);
    }

    /**
     * Validate whether the value is a positive integer
     * @param string $optionName
     * @param mixed $value
     * @param int $defaultValue
     * @return int
     * @throws \InvalidArgumentException
     */
    protected function validatePositiveInt(string $optionName, $value, int $defaultValue): int
    {
        if ($value === null) {
            return $defaultValue;
        }
        if (!is_int($value)) {
            $this->throwInvalidTypeException($optionName, 'int', gettype($value));
        }
        if ($value <= 0) {
            $this->throwInvalidValueException($optionName, 'positive int', $value);
        }

        return $value;
    }

    /**
     * Sets the notification stream
     * @param resource $stream Resource of the type stream with permission to write
     * @return void
     */
    protected function setNotificationStream($stream): void
    {
        if (!is_resource($stream)) {
            $this->throwInvalidTypeException('notification_stream', 'resource', gettype($stream));
        }

        // @codeCoverageIgnoreStart
        if (get_resource_type($stream) !== 'stream') {
            $this->throwInvalidValueException('resource type of notification_stream', 'stream', get_resource_type($stream));
        }
        // @codeCoverageIgnoreEnd

        $streamMeta = stream_get_meta_data($stream);
        if (!preg_match('/(w|r\+|a|a\+|x|x\+|c|c\+)/', $streamMeta['mode'])) {
            $this->throwInvalidValueException('file mode of notification_stream', 'write', $streamMeta['mode']);
        }
        $this->notificationStream = $stream;
    }

    /**
     * Set the modifications spec
     * @param array $modificationsSpec
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function setModificationsSpec(array $modificationsSpec): void
    {
        $faker = \Faker\Factory::create($this->getLocale());

        $this->modificationsSpec = [];

        foreach ($modificationsSpec as $table => $columnsSpec) {
            if (!is_string($table) || preg_match('#[./]#i', $table)) {
                $this->throwInvalidTableNameException($table);
            }
            $this->modificationsSpec[$table] = [];

            foreach ($columnsSpec as $columnNumber => $columnSpec) {
                if (!is_numeric($columnNumber) || $columnNumber < 1) {
                    $this->throwInvalidColumnNumberException($table, $columnNumber);
                }
                if (array_key_exists('quote', $columnSpec) && !is_bool($columnSpec['quote'])) {
                    $this->throwInvalidQuotingFlagException($table, $columnNumber, $columnSpec['quote']);
                }
                if (!array_key_exists('format', $columnSpec) || !is_callable([$faker, $columnSpec['format']])) {
                    $this->throwInvalidFormatException($table, $columnNumber, $columnSpec['format'] ?? null);
                }
                if (array_key_exists('args', $columnSpec) && !is_array($columnSpec['args'])) {
                    $this->throwInvalidArgsTypeException($table, $columnNumber, gettype($columnSpec['args']));
                }
                if (array_key_exists('unique', $columnSpec) && !is_bool($columnSpec['unique'])) {
                    $this->throwInvalidFlagTypeException('unique', 'bool', gettype($columnSpec['unique']), $table, $columnNumber);
                }
                if (array_key_exists('optional', $columnSpec) && !is_bool($columnSpec['optional'])) {
                    $this->throwInvalidFlagTypeException('optional', 'bool', gettype($columnSpec['optional']), $table, $columnNumber);
                }
                if (array_key_exists('optional_weight', $columnSpec) && !is_float($columnSpec['optional_weight'])) {
                    $this->throwInvalidFlagTypeException('optional_weight', 'float', gettype($columnSpec['optional_weight']), $table, $columnNumber);
                }
                $this->modificationsSpec[$table][intval($columnNumber)] = $columnSpec;
            }
        }
    }

    // Exceptions

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidTypeException(string $optionName, string $expectedType, $receivedType): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid type for "%s" (expected %s but got %s)',
                $optionName,
                $expectedType,
                $receivedType,
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidValueException(string $optionName, string $expectedValue, $receivedValue): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid value for "%s" (expected %s but got %s)',
                $optionName,
                $expectedValue,
                var_export($receivedValue, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidTableNameException($table): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid table name: %s',
                var_export($table, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidColumnNumberException($table, $columnNumber): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid column number in modifications spec for table %s: %s',
                var_export($table, true),
                var_export($columnNumber, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidQuotingFlagException($table, $columnNumber, $quoteFlag): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid column flag for quoting in modifications spec for table %s / column %s: %s',
                var_export($table, true),
                var_export($columnNumber, true),
                var_export($quoteFlag, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidFormatException($table, $columnNumber, $format): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid column format in modifications spec for table %s / column %s: %s',
                var_export($table, true),
                var_export($columnNumber, true),
                var_export($format, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidArgsTypeException($table, $columnNumber, string $type): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid type for the args in modifications spec for table %s / column %s: %s (expected array)',
                var_export($table, true),
                var_export($columnNumber, true),
                $type,
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidFlagTypeException(string $flag, string $expectedType, string $receivedType, $table, $columnNumber): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid type for the flag "%s" in modifications spec for table %s / column %s (expected %s but received %s)',
                $flag,
                var_export($table, true),
                var_export($columnNumber, true),
                $expectedType,
                $receivedType,
            ),
        );
    }
}
