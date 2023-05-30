<?php

declare(strict_types=1);

namespace Printi\DbDumpFile;

use PHPUnit\Framework\Attributes\CodeCoverageIgnore;
use Printi\DbDumpFile\Anonymizer\Config;

/**
 * Class to anonymize a DB dump file using fake data.
 * It uses \Printi\DbDumpFile\Anonymizer\Config to encapsulate optional configurations.
 * It uses \Printi\DbDumpFile\Parser to parse the tokens of a DB dump file,
 * then it determines whether the token must be kept or replaced, according to
 * the modifications spec (informed in the configuration object).
 */
class Anonymizer
{
    /** @var resource Stream resource of the input (file or stdin) */
    private $inputStream;

    /** @var resource Stream resource of the output (file or stdout) */
    private $outputStream;

    /** @var Config The configuration options for anonymization */
    private Config $config;

    /** @var array Faker instances */
    private array $fakers;

    /** @var string Write buffer */
    private string $writeBuffer;

    /**
     * Constructor
     * @param resource $inputStream
     * @param resource $outputStream
     * @param Config $config
     * @throws \InvalidArgumentException
     */
    public function __construct($inputStream, $outputStream, Config $config)
    {
        $this->config = $config;
        $this->setInputStream($inputStream);
        $this->setOutputStream($outputStream);

        $this->fakers = [];
        $this->writeBuffer = '';
    }

    /**
     * Execute the main logic to generate the modified dump file
     * @throws \RuntimeException
     */
    public function execute(): void
    {
        $appliedModifications = [];

        // Reserve space for the output file based on 110% of the input file size (to avoid file relocation)
        $outputStreamMeta = stream_get_meta_data($this->outputStream);
        if ($outputStreamMeta['seekable']) {
            ftruncate($this->outputStream, $this->calculateOutputSizeReservation());
        }

        $modificationsSpec = $this->getConfig()->getModificationsSpec();

        $tokens = $this->getTokens();

        [$i, $token] = [$tokens->key(), $tokens->current()];
        while ($tokens->valid()) {
            if (
                $token['type'] === Parser::TYPE_INSERT_START_TOKEN
                && isset($modificationsSpec[$token['table']])
            ) {
                $appliedModifications[] = $token['table'];

                $this->replaceInsertStatement($tokens);
            } else {
                $this->notifyToken($i, $token, 'TOKEN KEPT');
                $this->copyTokenToOutput($token);
            }
            $tokens->next();
            [$i, $token] = [$tokens->key(), $tokens->current()];
        }

        $this->flushOutputStream();

        $this->notify("Modified tables: %s\n", implode(', ', array_unique($appliedModifications)));

        // Truncating the output file to the expected size
        if ($outputStreamMeta['seekable']) {
            $outputFileSize = ftell($this->outputStream);
            ftruncate($this->outputStream, $outputFileSize);
        }
    }

    /**
     * Set the input stream
     * @param resource $inputStream
     * @return void
     * @throws \InvalidArgumentException
     */
    private function setInputStream($inputStream): void
    {
        if (!is_resource($inputStream)) {
            $this->throwInvalidArgumentTypeException('input stream', 'resource', gettype($inputStream));
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
     * Set the output stream
     * @param resource $outputStream
     * @return void
     * @throws \InvalidArgumentException
     */
    private function setOutputStream($outputStream): void
    {
        if (!is_resource($outputStream)) {
            $this->throwInvalidArgumentTypeException('output stream', 'resource', gettype($outputStream));
        }

        // @codeCoverageIgnoreStart
        if (get_resource_type($outputStream) !== 'stream') {
            $this->throwInvalidResourceTypeException('output stream', 'stream', get_resource_type($outputStream));
        }
        // @codeCoverageIgnoreEnd

        $streamMeta = stream_get_meta_data($outputStream);
        if (!preg_match('/(w|r\+|a|a\+|x|x\+|c|c\+)/', $streamMeta['mode'])) {
            $this->throwInvalidStreamModeException('output', 'write', $streamMeta['mode']);
        }
        $this->outputStream = $outputStream;
    }

    /**
     * Get the config
     * @return Config
     */
    protected function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Get a custom faker instance for a specific table/column
     * @param string $table
     * @param int $column
     * @return \Faker\Generator|\Faker\UniqueGenerator
     */
    protected function getFaker(string $table, int $column): object
    {
        if (!isset($this->fakers[$table])) {
            $this->fakers[$table] = [];
        }
        if (!isset($this->fakers[$table][$column])) {
            $faker = \Faker\Factory::create($this->getConfig()->getLocale());
            if ($this->getConfig()->getFakerSeed() !== null) {
                $faker->seed($this->getConfig()->getFakerSeed());
            }

            $columnModificationSpec = $this->getConfig()->getColumnModificationSpec($table, $column);

            if (!empty($columnModificationSpec['unique'])) {
                $faker = $faker->unique();
            }
            if (!empty($columnModificationSpec['optional'])) {
                $faker = $faker->optional(
                    $columnModificationSpec['optional_weight'] ?? 0.5,
                    $columnModificationSpec['optional_default_value'] ?? null
                );
            }

            $this->fakers[$table][$column] = $faker;
        }

        return $this->fakers[$table][$column];
    }

    /**
     * Calculate the size used to reserve space for the output stream
     * @return int
     */
    #[CodeCoverageIgnore]
    protected function calculateOutputSizeReservation(): int
    {
        $inputStreamMeta = stream_get_meta_data($this->inputStream);

        if ($inputStreamMeta['seekable']) {
            fseek($this->inputStream, 0, SEEK_END);
            $inputFileSize = ftell($this->inputStream);
            fseek($this->inputStream, 0, SEEK_SET);

            return intval($inputFileSize * 1.1);
        }

        return $this->getConfig()->getReserveOutputFileSize();
    }

    /**
     * Fetch the tokens from input stream
     * @return \Iterator
     */
    protected function getTokens(): \Iterator
    {
        $parserConfig = [
            'read_buffer_size' => $this->getConfig()->getReadBufferSize(),
            'tables' => array_keys($this->getConfig()->getModificationsSpec()),
        ];

        return (new Parser($this->inputStream, $parserConfig))->parseTokens();
    }

    /**
     * Copy the token to the output stream
     * @param array $token
     * @return void
     */
    protected function copyTokenToOutput(array $token): void
    {
        $this->writeToOutputStream($token['raw']);
    }

    /**
     * Replace the INSERT INTO statement with random values for the current position of the iterator
     * @param \Iterator $tokens
     * @return void
     */
    protected function replaceInsertStatement(\Iterator $tokens): void
    {
        [$i, $token] = [$tokens->key(), $tokens->current()];

        $currentTable = $token['table'];
        $modificationsSpec = $this->getConfig()->getModificationsSpec();
        $currentModificationsSpec = $modificationsSpec[$currentTable];

        $this->notifyToken($i, $token, sprintf('TABLE DETECTED %s', $currentTable));

        $this->copyTokenToOutput($token);

        $tokens->next();
        [$i, $token] = [$tokens->key(), $tokens->current()];
        while ($token['type'] !== Parser::TYPE_INSERT_END_TOKEN) {
            if ($token['type'] === Parser::TYPE_INSERT_TUPLE_TOKEN) {
                $this->notifyToken($i, $token, sprintf('TUPLE REPLACED %s', $currentTable));
                $this->replaceInsertTupleToken($token, $currentTable, $currentModificationsSpec);
            } elseif ($token['type'] === Parser::TYPE_INSERT_TUPLE_SEPARATOR_TOKEN) {
                $this->notifyToken($i, $token, 'TOKEN KEPT');
                $this->copyTokenToOutput($token);
            } else {
                $this->throwUnexpectedTokenException($token['type']);
            }
            $tokens->next();
            [$i, $token] = [$tokens->key(), $tokens->current()];
        }

        if ($token['type'] === Parser::TYPE_INSERT_END_TOKEN) {
            $this->notifyToken($i, $token, 'TOKEN KEPT');
            $this->copyTokenToOutput($token);
        } else {
            $this->throwUnexpectedTokenException($token['type']);
        }
    }

    /**
     * Replace the current token of an INSERT statement tuple by a modified tuple in the output stream
     * @param array $token
     * @param string $table
     * @param array $tableModificationsSpec
     * @return void
     */
    protected function replaceInsertTupleToken(array $token, string $table, array $tableModificationsSpec): void
    {
        $comma = '';
        $this->writeToOutputStream('(');
        foreach ($token['values'] as $column => $value) {
            $rawValue = isset($tableModificationsSpec[$column])
                ? $this->generateNewValue($table, $column, $tableModificationsSpec[$column], $value)
                : $value['raw'];
            $this->writeToOutputStream($comma . $rawValue);
            $comma = ',';
        }
        $this->writeToOutputStream(')');
    }

    /**
     * Generate a new value for a column of a table
     * @param string $table Table name
     * @param int $column
     * @param array $columnSpec
     *   string 'type' Type of the column (string|int)
     *   string 'format' Name of the format
     *   array 'args' Optional arguments for format
     * @param array $value
     *   string 'type' The type of the value (one of the consts Parser::VALUE_TYPE_...)
     *   string 'raw' The original raw value
     * @return string
     */
    protected function generateNewValue(string $table, int $column, array $columnSpec, array $value): string
    {
        if ($value['type'] === 'null') {
            return 'NULL';
        }
        $faker = $this->getFaker($table, $column);

        $fakeValue = call_user_func_array(
            [$faker, $columnSpec['format']],
            $columnSpec['args'] ?? []
        );

        if ($fakeValue === null) {
            return 'NULL';
        }

        return $columnSpec['quote']
            ? sprintf("'%s'", strtr($fakeValue, ["'" => "\\'"]))
            : sprintf('%s', $fakeValue);
    }

    /**
     * Write data into the output stream
     * Flushes the buffer data to output stream if the buffer reached the max size
     * @param string $data
     * @return void
     */
    protected function writeToOutputStream(string $data): void
    {
        $this->writeBuffer .= $data;
        if (strlen($this->writeBuffer) > $this->getConfig()->getWriteBufferSize()) {
            $this->flushOutputStream();
        }
    }

    /**
     * Flush the write buffer in the real output stream and clear the write buffer
     * @return void
     */
    protected function flushOutputStream(): void
    {
        fwrite($this->outputStream, $this->writeBuffer);
        $this->writeBuffer = '';
    }

    /**
     * Generates a notification about a token
     * @param int $tokenNumber
     * @param array $token
     * @param string $message
     * @return void
     */
    #[CodeCoverageIgnore]
    protected function notifyToken(int $tokenNumber, array $token, string $message): void
    {
        $text = mb_substr(strtr($token['raw'], ["\n" => '␤', "\r" => '␍', "\t" => '→']), 0, 50);
        $textLen = strlen($text) - mb_strlen($text) + 50;

        $this->notify(
            "Token %-10d [%-{$textLen}s] (size=%-10d): %s\n",
            $tokenNumber,
            $text,
            strlen($token['raw']),
            $message,
        );
    }

    /**
     * Sends a notification to the notification stream
     * @param string $format Format used by printf
     * @param ...$args Args used by printf
     * @return void
     */
    #[CodeCoverageIgnore]
    protected function notify(string $format, ...$args): void
    {
        if (!$this->getConfig()->getQuiet()) {
            vfprintf($this->getConfig()->getNotificationStream(), $format, $args);
        }
    }

    // Exceptions

    /** @throws \RuntimeException */
    #[CodeCoverageIgnore]
    protected function throwUnexpectedTokenException($tokenType): void
    {
        throw new \RuntimeException(sprintf('Unexpected token type: %s', $tokenType));
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidArgumentTypeException(string $argument, string $expectedType, string $receivedType): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid type for %s (expected %s / received %s)',
                $argument,
                $expectedType,
                $receivedType,
            ),
        );
    }

    /** @throws \InvalidArgumentException */
    #[CodeCoverageIgnore]
    protected function throwInvalidResourceTypeException(string $argument, string $expectedType, string $receivedType): void
    {
        throw new \InvalidArgumentException(
            sprintf(
                'Invalid resource type for %s (expected %s / received %s)',
                $argument,
                $expectedType,
                $receivedType,
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
}
