<?php

namespace Printi\DbDumpFile\Anonymizer;

/**
 * Configuration container for anonymization
 */
class Config
{
    const DEFAULT_LOCALE = 'en_US';
    const DEFAULT_QUIET_FLAG = false;
    const DEFAULT_RESERVE_OUTPUT_FILE_SIZE = 1052872704; // 1GB
    const DEFAULT_READ_BUFFER_SIZE = 512000; // 500KB
    const DEFAULT_WRITE_BUFFER_SIZE = 512000; // 500KB

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

    /**
     * Example:
     * [
     *     "customer" => [
     *         2 => ["type" => "int", "format" => "numberBetween", "args" => [1, 10]],
     *         5 => ["type" => "string", "format" => "firstName"],
     *         6 => ["type" => "string", "format" => "lastName"],
     *     ],
     * ]
     * @var array Specification of the desired modifications over the dump file
     */
    private array $modificationsSpec;

    /**
     * Constructor
     * @param array $config Associative array with:
     *   string 'locale' Locale of the Faker instance
     *   bool 'quiet' Wheter to omit messages
     *   int 'read_buffer_size' Buffer size for reading the input stream
     *   int 'reserve_output_file_size' Reserves the requested size for the output stream when it is seekable
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
                if (!empty($columnSpec['quote']) && !is_bool($columnSpec['quote'])) {
                    $this->throwInvalidQuotingFlagException($table, $columnNumber, $columnSpec['quote']);
                }
                if (empty($columnSpec['format']) || !is_callable([$faker, $columnSpec['format']])) {
                    $this->throwInvalidFormatException($table, $columnNumber, $columnSpec['format']);
                }
                if (isset($columnSpec['args']) && !is_array($columnSpec['args'])) {
                    $this->throwInvalidArgsTypeException($table, $columnNumber, gettype($columnSpec['args']));
                }
                if (isset($columnSpec['unique']) && !is_bool($columnSpec['unique'])) {
                    $this->throwInvalidFlagTypeException('unique', 'bool', gettype($columnSpec['unique']), $table, $columnNumber);
                }
                if (isset($columnSpec['optional']) && !is_bool($columnSpec['optional'])) {
                    $this->throwInvalidFlagTypeException('optional', 'bool', gettype($columnSpec['optional']), $table, $columnNumber);
                }
                if (isset($columnSpec['optional_weight']) && !is_float($columnSpec['optional_weight'])) {
                    $this->throwInvalidFlagTypeException('optional_weight', 'float', gettype($columnSpec['optional_weight']), $table, $columnNumber);
                }
                $this->modificationsSpec[$table][intval($columnNumber)] = $columnSpec;
            }
        }
    }

    /** @throws \InvalidArgumentException */
    protected function throwInvalidTypeException(string $optionName, string $expectedType, $receivedType): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid type for the option "%s" (expected %s but got %s)',
                $optionName,
                $expectedType,
                $receivedType,
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    protected function throwInvalidValueException(string $optionName, string $expectedValue, $receivedValue): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid value for the option "%s" (expected %s but got %s)',
                $optionName,
                $expectedValue,
                var_export($receivedValue, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
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
    protected function throwInvalidQuotingFlagException($table, $columnNumber, $quoteFlag): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid column flag for quoting in modifications spec for table %s / column %s: %s',
                var_export($table, true),
                var_export($columnNumber, true),
                var_export($columnSpec['quote'], true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
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
