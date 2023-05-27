<?php

declare(strict_types=1);

namespace Printi\DbDumpFile;

use PHPUnit\Framework\Attributes\CodeCoverageIgnore;

/**
 * Parser is responsible to parse a db dump file and split it into 5 different types of tokens:
 * TYPE_INSERT_START_TOKEN: that represents the start of a INSERT statament
 * TYPE_INSERT_TUPLE_TOKEN: that represents a single tuple of values of a INSERT statement
 * TYPE_INSERT_TUPLE_SEPARATOR_TOKEN: that represents a separator of a tuple in a INSERT statement
 * TYPE_INSERT_END_TOKEN: that represents the end of a INSERT statement
 * TYPE_CHUNK_TOKEN: that represents any other sequence of chars in the db dump file
 *
 * The main method is "parseTokens", that returns an Iterator optimized for read (cannot rewind).
 * The iterator returns the detected tokens.
 * Each token is represented by an associative array containing:
 *   int 'type' The type of the token (one of the const Parser::TYPE_...)
 *   string 'raw' The raw value detected from the input stream
 *   string 'table' The table name (when token is TYPE_INSERT_START_TOKEN)
 *   array 'values' The tuple values (when token is TYPE_INSERT_TUPLE_TOKEN)
 *
 * The key 'values' is represented by an array of associative arrays containing:
 *   string 'type' The type of the value (one of the const Parser::VALUE_TYPE_...)
 *   string 'raw' The raw value detected from the input stream
 */
class Parser
{
    public const DEFAULT_READ_BUFFER_SIZE = 512000; // 500KB

    /** Types of tokens */
    public const TYPE_INSERT_START_TOKEN = 1,    // "INSERT INTO `<table>` VALUES "
        TYPE_INSERT_TUPLE_TOKEN = 2,             // "('<value1>', '<value2>', ...)"
        TYPE_INSERT_TUPLE_SEPARATOR_TOKEN = 3,   // ","
        TYPE_INSERT_END_TOKEN = 4,               // ";"
        TYPE_CHUNK_TOKEN = 5;                    // Any other sequence of chars

    /** Types of values */
    public const VALUE_TYPE_STRING = 'string',
        VALUE_TYPE_NUMBER = 'number',
        VALUE_TYPE_NULL = 'null';

    /** @var resource The input stream of db dump file */
    private $inputStream;

    /**
     * @var array Config with:
     *   int 'read_buffer_size' The amount of bytes to read from input stream per time to speed up the process (default 1MB)
     *   array 'tables' The list of tables to parse the INSERT statements
     */
    private array $config;

    /** @var string The buffer of data to keep in memory for parsing the input stream */
    private string $buffer;

    /** @var int Current position of the cursor in the input stream */
    private int $currentCursorPosition;

    /**
     * Constructor
     * @param resource $inputStream A stream resource of type "stream", that might be seekable or not (it can be STDIN)
     * @param array $config Config with:
     *   int 'read_buffer_size' The amount of bytes to read from input stream per time to speed up the process (default 1MB)
     *   array 'tables' The list of tables to parse the INSERT statements
     * @throws \InvalidArgumentException
     */
    public function __construct($inputStream, array $config = [])
    {
        $this->setInputStream($inputStream);
        $this->setConfig($config);
        $this->buffer = '';
        $this->currentCursorPosition = 0;
    }

    /**
     * Parse the tokens of the input stream and return an Iterator that is optimized for read (cannot rewind)
     * @return \Iterator
     * @throws \RuntimeException
     */
    public function parseTokens(): \Iterator
    {
        $i = 0;
        foreach ($this->parseTokensWithoutKeys() as $token) {
            yield $i++ => $token;
        }
    }

    /**
     * Method used by parseTokens to return the iterator of tokens without the keys (index)
     * @return \Iterator
     * @throws \RuntimeException
     */
    protected function parseTokensWithoutKeys(): \Iterator
    {
        while (!$this->isEndOfFile()) {
            $tableName = $this->detectInsertIntoStatement();
            if ($tableName && in_array($tableName, $this->config['tables'])) {
                yield from $this->parseInsertIntoStatement($tableName);
            } else {
                yield from $this->parseLine();
            }
        }
    }

    /**
     * Set the input stream
     * @param resource $inputStream
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function setInputStream($inputStream): void
    {
        if (!is_resource($inputStream)) {
            $this->throwInvalidTypeException('input stream', 'resource', gettype($inputStream));
        }

        // @codeCoverageIgnoreStart
        if (get_resource_type($inputStream) !== 'stream') {
            $this->throwInvalidResourceTypeException('input stream', 'stream', get_resource_type($inputStream));
        }
        // @codeCoverageIgnoreEnd

        $streamMeta = stream_get_meta_data($inputStream);
        if (!preg_match('/(r|r\+|w\+|a\+|x\+|c\+)/', $streamMeta['mode'])) {
            $this->throwInvalidStreamModeException('input', 'read', $streamMeta['mode']);
        }
        $this->inputStream = $inputStream;
    }

    /**
     * Set the config
     * @param array $config See the attribute $config for details
     * @return void
     * @throws \InvalidArgumentException
     */
    protected function setConfig(array $config): void
    {
        $this->config = array_merge(
            [
                'read_buffer_size' => self::DEFAULT_READ_BUFFER_SIZE,
                'tables' => [],
            ],
            $config
        );

        if (!is_int($this->config['read_buffer_size'])) {
            $this->throwInvalidTypeException('config key "read_buffer_size"', 'int', gettype($this->config['read_buffer_size']));
        }
        if ($this->config['read_buffer_size'] <= 0) {
            $this->throwInvalidValueException('config key "read_buffer_size"', 'positive int', $this->config['read_buffer_size']);
        }
        if (!is_array($this->config['tables'])) {
            $this->throwInvalidTypeException('config key "tables"', 'array', gettype($this->config['tables']));
        }
    }

    /**
     * Detects if the current position of the buffer cursor is a INSERT statement
     * @return string|null The table name of the detected INSERT statement or null
     */
    protected function detectInsertIntoStatement(): ?string
    {
        $tableName = null;

        $insertIntoLength = 250; // strlen('INSERT INTO `{$table_name_with_128_chars}` VALUES ') + 100 extra spaces for safety
        $chunk = $this->checkBytesFromCurrentPosition($insertIntoLength, false);

        if (preg_match('/^\s*INSERT\s+INTO\s*`(?<table_name>(?:[^`]|``)+)`\s*VALUES\s*/ui', $chunk, $matches)) {
            $tableName = strtr($matches['table_name'], ['``' => '`']);
        }

        return $tableName;
    }

    /**
     * Parse and return an iterator of the INSERT statement tokens
     * @param string $tableName Previously detected table name
     * @return \Iterator
     * @throws \RuntimeException
     */
    protected function parseInsertIntoStatement(string $tableName): \Iterator
    {
        // Read "INSERT INTO `<table_name>` VALUES "
        $raw = $this->readInsertIntoStart($tableName);
        yield [
            'type' => self::TYPE_INSERT_START_TOKEN,
            'raw' => $raw,
            'table' => $tableName
        ];

        $hasNextTuple = true;
        do {
            // Read tuple from "(" to ")" and extra whitespaces
            $tuple = $this->readInsertIntoTuple();
            yield [
                'type' => self::TYPE_INSERT_TUPLE_TOKEN,
                'raw' => $tuple['raw'],
                'values' => $tuple['values'],
            ];

            // Check if there is a next tuple or it is the end of INSERT statement
            $nextChar = $this->checkBytesFromCurrentPosition(1);
            if ($nextChar === ',') {
                $raw = $this->readString(',', false) . $this->readWhitespaces();
                yield [
                    'type' => self::TYPE_INSERT_TUPLE_SEPARATOR_TOKEN,
                    'raw' => $raw,
                ];
            } elseif ($nextChar === ';') {
                $raw = $this->readString(';', false) . $this->readWhitespaces();
                yield [
                    'type' => self::TYPE_INSERT_END_TOKEN,
                    'raw' => $raw,
                ];
                $hasNextTuple = false;
            } else {
                $this->throwUnexpectedCharException('after tuple', '"," or ";"', $nextChar, 'last tuple', $tuple['raw']);
            }
        } while ($hasNextTuple);
    }

    /**
     * Read the start of a INSERT statement from input stream
     * @param string $tableName Previously detected table name
     * @return string Raw value of the start of the INSERT statement
     * @throws \RuntimeException
     */
    protected function readInsertIntoStart(string $tableName): string
    {
        return $this->readWhitespaces()
            . $this->readStringCaseInsensitive('INSERT')
            . $this->readWhitespaces(1)
            . $this->readStringCaseInsensitive('INTO')
            . $this->readWhitespaces()
            . $this->readString('`')
            . $this->readString(strtr($tableName, ['`' => '``']))
            . $this->readString('`')
            . $this->readWhitespaces()
            . $this->readStringCaseInsensitive('VALUES')
            . $this->readWhitespaces();
    }

    /**
     * Read a tuple of a INSERT statement from input stream.
     * Example: "(1, 'sample', NULL)"
     * @return array Associative array with:
     *   string 'raw' The raw value of the tuple
     *   array 'values' The array of detected values (associative array with "type" and "value")
     * @throws \RuntimeException
     */
    protected function readInsertIntoTuple(): array
    {
        $tuple = [
            'raw' => '',
            'values' => [],
        ];

        $tuple['raw'] .= $this->readString('(');
        $tuple['raw'] .= $this->readWhitespaces();

        $columnPosition = 1;

        $hasNextValue = true;
        do {
            $value = $this->readTupleValue();

            $tuple['values'][$columnPosition] = $value;
            $tuple['raw'] .= $value['raw'];
            $tuple['raw'] .= $this->readWhitespaces();
            $columnPosition += 1;

            // Check if there is a next value or it is the end of the tuple
            $nextChar = $this->checkBytesFromCurrentPosition(1);
            if ($nextChar === ',') {
                $tuple['raw'] .= $this->readString(',', false);
                $tuple['raw'] .= $this->readWhitespaces();
            } elseif ($nextChar === ')') {
                $hasNextValue = false;
            } else {
                $context = 'after the end of a tuple value';
                $this->throwUnexpectedCharException($context, '"," or ")"', $nextChar, 'last tuple', $value['raw']);
            }
        } while ($hasNextValue);

        $tuple['raw'] .= $this->readString(')');

        return $tuple;
    }

    /**
     * Read a value of a tuple of a INSERT statement from input stream.
     * Examples: "1", "-1" "'sample'", "NULL"
     * @return array Associative array with:
     *   string 'type' One of the consts self::VALUE_TYPE_...
     *   string 'raw' Raw value
     * @throws \RuntimeException
     */
    protected function readTupleValue(): array
    {
        $value = [
            'type' => null,
            'raw' => '',
        ];

        $firstChar = $this->checkBytesFromCurrentPosition(1);

        // Read Number
        if (ctype_digit($firstChar) || $firstChar == '-') {
            $value['type'] = self::VALUE_TYPE_NUMBER;
            $value['raw'] = $this->readNumberValue();
            return $value;
        }

        // Read String
        if ($firstChar === '"' || $firstChar === "'") {
            $value['type'] = self::VALUE_TYPE_STRING;
            $value['raw'] = $this->readStringValue();
            return $value;
        }

        // Read NULL
        if (
            strcasecmp($firstChar, 'N') === 0
            && strcasecmp($this->checkBytesFromCurrentPosition(4, false), 'NULL') === 0
        ) {
            $value['type'] = self::VALUE_TYPE_NULL;
            $value['raw'] = $this->readStringCaseInsensitive('NULL', false);
            return $value;
        }

        $context = 'in the context of a value';
        $expected = 'a number, a string or NULL';
        $nextBytes = $this->checkBytesFromCurrentPosition(20, false);
        $this->throwUnexpectedCharException($context, $expected, $firstChar, 'next bytes', $nextBytes);
    }

    /**
     * Read a Number value from current cursor position
     * @return string
     */
    protected function readNumberValue(): string
    {
        $raw = '';
        $endOfNumber = false;
        while (!$this->isEndOfFile() && !$endOfNumber) {
            $char = $this->checkBytesFromCurrentPosition(1, false);
            $endOfNumber = $char !== '.'
                && $char !== '-'
                && !ctype_digit($char)
                && $char !== 'e';
            if (!$endOfNumber) {
                $raw .= $this->readString($char, false);
            }
        }

        return $raw;
    }

    /**
     * Read a String value from current cursor position
     * @return string
     * @throws \RuntimeException
     */
    protected function readStringValue(): string
    {
        $raw = '';

        $stringDelimiter = $this->checkBytesFromCurrentPosition(1);

        $raw = $this->readString($stringDelimiter, false);
        $endOfString = false;
        while (!$this->isEndOfFile() && !$endOfString) {
            $char = $this->checkBytesFromCurrentPosition(1, false);

            if ($char === '\\') {
                $raw .= $this->readString($char, false);

                $specialChar = $this->checkBytesFromCurrentPosition(1);
                $raw .= $this->readString($specialChar, false);
                continue;
            }

            $endOfString = $char === $stringDelimiter;
            if (!$endOfString) {
                $raw .= $this->readString($char, false);
            }
        }
        $raw .= $this->readString($stringDelimiter, false);

        return $raw;
    }

    /**
     * Parse the current position of the input stream until the end of the line
     * then return an iterator with all the read tokens
     * @return \Iterator
     */
    protected function parseLine(): \Iterator
    {
        while (!$this->isEndOfFile()) {
            $chunk = $this->checkBytesFromCurrentPosition($this->config['read_buffer_size'], false);

            $newLinePosition = strpos($chunk, "\n");

            if ($newLinePosition === false) {
                $this->readString($chunk, false);
                yield [
                    'type' => self::TYPE_CHUNK_TOKEN,
                    'raw' => $chunk,
                ];
            } else {
                $chunkPiece = substr($chunk, 0, $newLinePosition + 1);

                $this->readString($chunkPiece, false);
                yield [
                    'type' => self::TYPE_CHUNK_TOKEN,
                    'raw' => $chunkPiece,
                ];
                return;
            }
        }
    }

    /**
     * Read an amount of bytes from the current position of the stream, but do not advance the buffer cursor
     * @param string $size Expected number of bytes to read
     * @param bool $throwOnUnexpectedEof Whether to throw an exception when there is an unexpected end of file
     * @return string
     * @throws \RuntimeException
     */
    protected function checkBytesFromCurrentPosition(int $size, bool $throwOnUnexpectedEof = true): string
    {
        while (
            $size > strlen($this->buffer)
            && !feof($this->inputStream)
        ) {
            $this->buffer .= fread($this->inputStream, $size - strlen($this->buffer));
        }

        if (
            $throwOnUnexpectedEof
            && strlen($this->buffer) < $size
            && feof($this->inputStream)
        ) {
            $this->throwUnexpectedEofException($size);
        }

        return substr($this->buffer, 0, $size);
    }

    /**
     * Read a string from current position of the stream and advance the buffer cursor
     * @param string $str Expected string to read
     * @param bool $throwOnUnexpectedValue Whether to throw an exception when an unexpected value is read
     * @return string
     * @throws \RuntimeException
     */
    protected function readString(string $str, bool $throwOnUnexpectedValue = true): string
    {
        $strLen = strlen($str);
        $chunk = $this->checkBytesFromCurrentPosition($strLen);

        if (
            $throwOnUnexpectedValue
            && strcmp($str, $chunk) !== 0
        ) {
            $this->throwUnexpectedStringException($str, $chunk);
        }
        $this->buffer = substr($this->buffer, $strLen);
        $this->currentCursorPosition += $strLen;

        return $chunk;
    }

    /**
     * Read a string from current position of the stream, using case insentive comparison, and advance the buffer cursor
     * @param string $str Expected string to read
     * @param bool $throwOnUnexpectedValue Whether to throw an exception when an unexpected value is read
     * @return string
     * @throws \RuntimeException
     */
    protected function readStringCaseInsensitive(string $str, bool $throwOnUnexpectedValue = true): string
    {
        $strLen = strlen($str);
        $chunk = $this->checkBytesFromCurrentPosition($strLen);

        if (
            $throwOnUnexpectedValue
            && strcasecmp($str, $chunk) !== 0
        ) {
            $this->throwUnexpectedStringException($str, $chunk);
        }
        $this->buffer = substr($this->buffer, $strLen);
        $this->currentCursorPosition += $strLen;

        return $chunk;
    }

    /**
     * Read a sequence of whitespace symbols (space, tab, carriage-return or new-line)
     * @param int $minimumLength The minimum length accepted to be returned (throws an exception if it is lower than the expected min-size)
     * @return string The sequence of bytes that is formed by whitespace symbols
     * @throws \RuntimeException
     */
    protected function readWhitespaces(int $minimumLength = 0): string
    {
        $whitespaces = $this->readChars([' ', "\t", "\r", "\n"]);

        if (strlen($whitespaces) < $minimumLength) {
            $this->throwUnexpectedMinCharClassException('whitespaces', $minimumLength, strlen($whitespaces));
        }

        return $whitespaces;
    }

    /**
     * Read a sequence of chars from current position of the stream and advance the buffer cursor
     * @param array $chars
     * @return string
     */
    protected function readChars(array $chars): string
    {
        $raw = '';

        $char = $this->checkBytesFromCurrentPosition(1, false);
        while (in_array($char, $chars, true)) {
            $raw .= $char;
            $this->buffer = substr($this->buffer, 1);
            $this->currentCursorPosition += 1;

            $char = $this->checkBytesFromCurrentPosition(1, false);
        }

        return $raw;
    }

    /**
     * Return whether it is the end of input stream and the end of the buffer
     * @return bool
     */
    protected function isEndOfFile(): bool
    {
        return $this->buffer === ''
            && feof($this->inputStream);
    }

    // Exceptions

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidTypeException(string $inputName, string $expectedType, string $receivedType): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid type for %s (expected %s but got %s)',
                $inputName,
                $expectedType,
                $receivedType,
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidValueException(string $inputName, string $expectedValue, $receivedValue): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid value for %s (expected %s but got %s)',
                $inputName,
                $expectedValue,
                var_export($receivedValue, true),
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidResourceTypeException(string $resourceName, string $expectedResourceType, $receivedResourceType): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid resource type for %s (expected %s but got %s)',
                $resourceName,
                $expectedResourceType,
                $receivedResourceType,
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidStreamModeException(string $streamName, string $expectedMode, string $receivedMode): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'The %s stream is not opened for %s (mode=%s)',
                $streamName,
                $expectedMode,
                $receivedMode,
            ),
        );
    }

    /** @throws \RuntimeException */
    #[CodeCoverageIgnore]
    protected function throwUnexpectedCharException(string $context, string $expected, string $received, string $bytesType, string $bytes): void
    {
        throw new \RuntimeException(
            sprintf(
                'Unexpected char %s (expected %s but got "%s" at position %d / %s = "%s")',
                $context,
                $expected,
                $received,
                $this->currentCursorPosition,
                $bytesType,
                $bytes,
            ),
        );
    }

    /** @throws \RuntimeException */
    #[CodeCoverageIgnore]
    protected function throwUnexpectedEofException(int $size): void
    {
        throw new \RuntimeException(
            sprintf(
                'Unexpected end of file while reading %d bytes from input stream at position %d',
                $size,
                $this->currentCursorPosition,
            ),
        );
    }

    /** @throws \RuntimeException */
    #[CodeCoverageIgnore]
    protected function throwUnexpectedStringException(string $expectedString, string $receivedString): void
    {
        throw new \RuntimeException(
            sprintf(
                'Unexpected string (expected "%s" but got "%s" at position %d)',
                $expectedString,
                $receivedString,
                $this->currentCursorPosition,
            ),
        );
    }

    /** @throws \RuntimeException */
    #[CodeCoverageIgnore]
    protected function throwUnexpectedMinCharClassException(string $charClass, int $expectedMinimum, int $receivedLength): void
    {
        throw new \RuntimeException(
            sprintf(
                'Unexpected minimum number of %s (expected %d but got %d at position %d)',
                $charClass,
                $expectedMinimum,
                $receivedLength,
                $this->currentCursorPosition,
            ),
        );
    }
}
