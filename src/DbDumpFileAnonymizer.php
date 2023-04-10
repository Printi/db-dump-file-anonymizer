<?php

namespace Printi;

/**
 * Class to anonymize a DB dump file using fake data
 */
class DbDumpFileAnonymizer
{
    /** @var array Specification of the desired modifications over the dump file */
    private array $modificationsSpec;

    /** @var resource File stream resource of the dump file */
    private $inputFileHandler;

    /** @var resource Stream resource of the output (file or stdout) */
    private $outputFileHandler;

    /** @var string Locale used by faker instances */
    private string $locale;

    /** @var array Faker instances */
    private array $fakers;

    /** @var bool Whether to ommit notifications or not */
    private bool $quiet;

    /**
     * Constructor
     * @param resource $inputFileHandler
     * @param resource $outputFileHandler
     * @param string $locale Locale of the Faker instance
     * @param array $modificationsSpec
     * @param bool $quiet
     */
    public function __construct($inputFileHandler, $outputFileHandler, string $locale, array $modificationsSpec, bool $quiet = false)
    {
        $this->fakers = [];
        $this->locale = $locale;
        $this->quiet = $quiet;

        $this->setInputFileHandler($inputFileHandler);
        $this->setOutputFileHandler($outputFileHandler);
        $this->setModificationsSpec($modificationsSpec);
    }

    /**
     * Get a custom faker instance for a specific table/column
     * @param string $table
     * @param int $column
     * @return \Faker\Generator|\Faker\UniqueGenerator
     */
    private function getFaker(string $table, int $column): object
    {
        if (!isset($this->fakers[$table])) {
            $this->fakers[$table] = [];
        }
        if (!isset($this->fakers[$table][$column])) {
            $faker = \Faker\Factory::create($this->locale);
            if (!empty($this->modificationsSpec[$table][$column]['unique'])) {
                $faker = $faker->unique();
            }
            if (!empty($this->modificationsSpec[$table][$column]['optional'])) {
                $faker = $faker->optional(
                    $this->modificationsSpec[$table][$column]['optional_weight'] ?? 0.5,
                    $this->modificationsSpec[$table][$column]['optional_default_value'] ?? null
                );
            }

            $this->fakers[$table][$column] = $faker;
        }
        return $this->fakers[$table][$column];
    }

    /**
     * Set the input file handler
     * @param resource $inputFileHandler
     * @return void
     */
    private function setInputFileHandler($inputFileHandler): void
    {
        if (!is_resource($inputFileHandler)) {
            throw new \InvalidArgumentException(sprintf('Invalid argument for input file handler (expected resource / received %s)', gettype($inputFileHandler)));
        }
        if (get_resource_type($inputFileHandler) !== 'stream') {
            throw new \InvalidArgumentException(sprintf('Invalid resource type for input file handler (expected stream / received %s)', get_resource_type($inputFileHandler)));
        }
        $streamMeta = stream_get_meta_data($inputFileHandler);
        if (!preg_match('/(r|r\+|w\+|a\+|x\+|c\+)/', $streamMeta['mode'])) {
            throw new \InvalidArgumentException(sprintf('The input file handler is not opened for read (mode=%s)', $streamMeta['mode']));
        }
        if (!$streamMeta['seekable']) {
            throw new \InvalidArgumentException('The input file handler is not seekable');
        }
        $this->inputFileHandler = $inputFileHandler;
    }

    /**
     * Set the output file handler
     * @param resource $outputFileHandler
     * @return void
     */
    private function setOutputFileHandler($outputFileHandler): void
    {
        if (!is_resource($outputFileHandler)) {
            throw new \InvalidArgumentException(sprintf('Invalid argument for output file handler (expected resource / received %s)', gettype($outputFileHandler)));
        }
        if (get_resource_type($outputFileHandler) !== 'stream') {
            throw new \InvalidArgumentException(sprintf('Invalid resource type for output file handler (expected stream / received %s)', get_resource_type($outputFileHandler)));
        }
        $streamMeta = stream_get_meta_data($outputFileHandler);
        if (!preg_match('/(w|r\+|a|a\+|x|x\+|c|c\+)/', $streamMeta['mode'])) {
            throw new \InvalidArgumentException(sprintf('The output file handler is not opened for write (mode=%s)', $streamMeta['mode']));
        }
        $this->outputFileHandler = $outputFileHandler;
    }

    /**
     * Set the modifications spec
     * @param array $modificationsSpec
     * @return void
     */
    private function setModificationsSpec(array $modificationsSpec): void
    {
        $faker = \Faker\Factory::create($this->locale);

        foreach ($modificationsSpec as $table => $columnsSpec) {
            if (!is_string($table) || preg_match('#[./]#i', $table)) {
                throw new \InvalidArgumentException(sprintf('Invalid table name in modifications spec: %s', $table));
            }
            foreach ($columnsSpec as $columnNumber => $columnSpec) {
                if (!is_int($columnNumber) || $columnNumber < 1) {
                    throw new \InvalidArgumentException(sprintf('Invalid column number in modifications spec for table %s: %s', $table, $columnNumber));
                }
                if (!empty($columnSpec['quote']) && !is_bool($columnSpec['quote'])) {
                    throw new \InvalidArgumentException(sprintf('Invalid column flag for quoting in modifications spec for table %s / column %s: %s', $table, $columnNumber, var_export($columnSpec['quote'], true)));
                }
                if (empty($columnSpec['format']) || !is_callable([$faker, $columnSpec['format']])) {
                    throw new \InvalidArgumentException(sprintf('Invalid column format in modifications spec for table %s / column %s: %s', $table, $columnNumber, $columnSpec['format']));
                }
                if (isset($columnSpec['args']) && !is_array($columnSpec['args'])) {
                    throw new \InvalidArgumentException(sprintf('Invalid type for the args in modifications spec for table %s / column %s: %s (expected array)', $table, $columnNumber, gettype($columnSpec['args'])));
                }
            }
        }

        $this->modificationsSpec = $modificationsSpec;
    }

    /**
     * Execute the main logic to generate the modified dump file
     */
    public function execute(): void
    {
        $appliedModifications = [];

        fseek($this->inputFileHandler, 0, SEEK_END);
        $inputFileSize = ftell($this->inputFileHandler);
        fseek($this->inputFileHandler, 0, SEEK_SET);

        // Reserve the same size of input file to avoid file relocation
        $outputStreamMeta = stream_get_meta_data($this->outputFileHandler);
        if ($outputStreamMeta['seekable']) {
            ftruncate($this->outputFileHandler, $inputFileSize);
        }

        while (!feof($this->inputFileHandler)) {
            $table = $this->detectInsertIntoLine();
            if ($table) {
                $this->notify("Table detected: %s\n", $table);
                $this->replaceInsertIntoLine($table, $this->modificationsSpec[$table]);
                $appliedModifications[] = $table;
            } else {
                $this->notify("Keeping line\n");
                $this->copyLineFromInputToOutput();
            }
        }

        $this->notify("Modified tables:\n%s\n", implode(', ', array_unique($appliedModifications)));

        if ($outputStreamMeta['seekable']) {
            $outputFileSize = ftell($this->outputFileHandler);
            ftruncate($this->outputFileHandler, $outputFileSize);
        }
    }

    /**
     * Copy the current line of the input stream to the output stream
     * @return void
     */
    private function copyLineFromInputToOutput(): void
    {
        $initPos = ftell($this->inputFileHandler);
        $this->skipToChar("\n", ['throw_on_end' => false]);
        $endPos = ftell($this->inputFileHandler);

        fseek($this->inputFileHandler, $initPos, SEEK_SET);
        stream_copy_to_stream($this->inputFileHandler, $this->outputFileHandler, $endPos + 1 - $initPos, $initPos);
        fseek($this->inputFileHandler, $endPos, SEEK_SET);
        fgetc($this->inputFileHandler);
    }

    /**
     * Detect if current line of file handler is a "INSERT INTO" of
     * a table marked to be modified.
     * Always return the file cursor to the initial position.
     *
     * @return null|string Name of the detected table or null
     */
    private function detectInsertIntoLine(): ?string
    {
        $len = 150; // strlen('INSERT INTO ') + 1 + 128 + 1 + strlen(' VALUES ');

        $pos = ftell($this->inputFileHandler);

        $this->skipChars([' ', "\t"]);

        $startOfLine = fread($this->inputFileHandler, $len);
        fseek($this->inputFileHandler, $pos, SEEK_SET);

        if (!preg_match('/^INSERT\s+INTO\s+`(?<table_name>(?:[^`]|``)+)`\s+VALUES\s+/i', $startOfLine, $matches)) {
            return null;
        }
        $tableName = strtr($matches['table_name'], ['``' => '`']);

        return isset($this->modificationsSpec[$tableName]) ? $tableName : null;
    }

    /**
     * Advance the cursor of the file handler while it reads some chars
     * @param array $chars
     * @return void
     */
    private function skipChars(array $chars): void
    {
        $pos = ftell($this->inputFileHandler);
        while (!feof($this->inputFileHandler)) {
            $char = fgetc($this->inputFileHandler);
            if (!in_array($char, $chars)) {
                fseek($this->inputFileHandler, -1, SEEK_CUR);
                return;
            }
        }
    }

    /**
     * Advance the cursor of the file handler while it reads some chars
     * and write these chars on output file
     * @param array $chars
     * @return void
     */
    private function copyChars(array $chars): void
    {
        $pos = ftell($this->inputFileHandler);
        while (!feof($this->inputFileHandler)) {
            $char = fgetc($this->inputFileHandler);
            if (!in_array($char, $chars)) {
                fseek($this->inputFileHandler, -1, SEEK_CUR);
                return;
            }
            fwrite($this->outputFileHandler, $char, 1);
        }
    }

    /**
     * Advance the cursor of the file handler ignoring a specific string from the current position of the cursor.
     * Throws an exception if the current position does not contains the expected string.
     * @param string $str
     * @return void
     */
    private function skipString(string $str): void
    {
        $pos = ftell($this->inputFileHandler);
        $read = fread($this->inputFileHandler, strlen($str));
        if ($read !== $str) {
            throw new \RuntimeException(sprintf('Failed to skip the string "%s" at position %d', $str, $pos));
        }
    }

    /**
     * Advance the cursor of the file handler copying a specific string from the current position of the cursor to the output file.
     * Throws an exception if the current position does not contains the expected string.
     * @param string $str
     * @return void
     */
    private function copyString(string $str): void
    {
        $pos = ftell($this->inputFileHandler);
        $read = fread($this->inputFileHandler, strlen($str));
        if ($read !== $str) {
            throw new \RuntimeException(sprintf('Failed to skip the string "%s" at position %d', $str, $pos));
        }
        fwrite($this->outputFileHandler, $read);
    }

    /**
     * Advance the cursor of the file handler to the first occurrence of a char
     * @param string $char
     * @param array $options
     *     int 'chunk_size' Number of bytes to read on each iteration
     *     int 'offset' Offset of the position of the found char to move
     *     bool 'throw_on_end' Whether to throw an exception if the end of file is found before the expected char
     * @return void
     */
    private function skipToChar(string $char, array $options = []): void
    {
        $options = array_merge(['chunk_size' => 128, 'offset' => 0, 'throw_on_end' => true], $options);

        $pos = ftell($this->inputFileHandler);
        $charPos = false;
        while (!feof($this->inputFileHandler) && $charPos === false) {
            $chunk = fread($this->inputFileHandler, $options['chunk_size']);
            $charPos = strpos($chunk, $char);
        }
        if ($charPos !== false) {
            fseek($this->inputFileHandler, $charPos + $options['offset'] - strlen($chunk), SEEK_CUR);
        }
        if ($options['throw_on_end'] && feof($this->inputFileHandler)) {
            throw new \RuntimeException(
                sprintf(
                    'Failed to advance to the char %s at %d',
                    ctype_print($char) ? '"' . $char . '"' : '#' . ord($char),
                    $pos
                )
            );
        }
    }

    /**
     * Replace the INSERT INTO statement from the input stream to the output stream using fake data
     * @param string $table
     * @param array $columnsSpec
     * @return void
     */
    private function replaceInsertIntoLine(string $table, array $columnsSpec): void
    {
        // Copy "INSERT INTO `table` VALUES "
        $this->copyString(sprintf('INSERT INTO `%s` VALUES ', strtr($table, ['`' => '``'])));

        $end = false;
        while (!feof($this->inputFileHandler) && !$end) {
            $this->copyChars([' ', "\t", "\n", "\r"]);
            $this->replaceInsertIntoRecord($table, $columnsSpec);
            $this->copyChars([' ', "\t", "\n", "\r"]);

            if ($this->nextStringIs(',')) {
                $this->copyString(',');
                $this->copyChars([' ', "\t", "\n", "\r"]);
            } elseif ($this->nextStringIs(';')) {
                $end =  true;
            } else {
                throw new \RuntimeException(sprintf('Unexpected next token at position %d (expected "," or ";" / got "%s")', ftell($this->inputFileHandler), fgetc($this->inputFileHandler)));
            }
        }
        $this->copyString(';');
        $this->copyChars([' ', "\t", "\n", "\r"]);
    }

    /**
     * Replace the record delimited by "(" and ")" of a INSERT INTO statement with fake data
     * and write into the output file
     * @param string $table Table name
     * @param array $columnsSpec
     * @return void
     */
    private function replaceInsertIntoRecord(string $table, array $columnsSpec): void
    {
        $pos = ftell($this->inputFileHandler);
        $this->copyString('(');

        $column = 1;
        $end = false;
        while (!feof($this->inputFileHandler) && !$end) {
            if (isset($columnsSpec[$column])) {
                $this->replaceInsertIntoValue($table, $column, $columnsSpec[$column]);
            } else {
                $this->copyInsertIntoValue();
            }

            $column += 1;
            if ($this->nextStringIs(',')) {
                $this->copyString(',');
            } elseif ($this->nextStringIs(')')) {
                $end = true;
            } else {
                throw new \RuntimeException(sprintf('Unexpected next token at position %d (expected "," or ")" / got "%s")', ftell($this->inputFileHandler), fgetc($this->inputFileHandler)));
            }
        }
        if (feof($this->inputFileHandler)) {
            throw new \RuntimeException(sprintf('Failed to detect the end of a record at position %d', $pos));
        }

        $this->copyString(')');
    }

    /**
     * Replace the value of an INSERT INTO statement
     * @param string $table Table name
     * @param int $column
     * @param array $columnSpec
     *   string 'type' Type of the column (string|int)
     *   string 'format' Name of the format
     *   array 'args' Optional arguments for format
     * @return void
     */
    private function replaceInsertIntoValue(string $table, int $column, array $columnSpec): void
    {
        $valueSize = $this->detectInsertIntoValueLength();

        $newValue = $this->generateNewValue($table, $column, $columnSpec, $valueSize);

        fseek($this->inputFileHandler, $valueSize['size'], SEEK_CUR);
        fwrite($this->outputFileHandler, $newValue);
    }

    /**
     * Generate a new value for a column of a table
     * @param string $table Table name
     * @param int $column
     * @param array $columnSpec
     *   string 'type' Type of the column (string|int)
     *   string 'format' Name of the format
     *   array 'args' Optional arguments for format
     * @param array $valueSize
     *   int 'size' The size of the original value
     *   bool 'null' Whether the original value was NULL
     * @return string
     */
    private function generateNewValue(string $table, int $column, array $columnSpec, array $valueSize): string
    {
        if ($valueSize['null']) {
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
     * Advance the cursor of a file handler copying a value of a INSERT INTO statement
     * @return void
     */
    private function copyInsertIntoValue(): void
    {
        $valueSize = $this->detectInsertIntoValueLength();

        stream_copy_to_stream($this->inputFileHandler, $this->outputFileHandler, $valueSize['size']);
    }

    /**
     * Detect the number of bytes of a value of a INSERT INTO statement
     * in the current position of the cursor of a file handler
     * @return array
     *    int 'size'
     *    bool 'null'
     */
    private function detectInsertIntoValueLength(): array
    {
        $pos = ftell($this->inputFileHandler);

        $this->skipChars([' ', "\t", "\n", "\r"]);

        $firstByte = fgetc($this->inputFileHandler);

        // Number
        if (ctype_digit($firstByte) || $firstByte === '-') {
            $end = false;
            while (!feof($this->inputFileHandler) && !$end) {
                $char = fgetc($this->inputFileHandler);
                $end = $char !== '.' && !ctype_digit($char) && $char !== 'e';
            }
            if (feof($this->inputFileHandler)) {
                throw new \RuntimeException(sprinf('Failed to detect the end of a number value at position %d', $pos));
            }
            fseek($this->inputFileHandler, -1, SEEK_CUR);
            $this->skipChars([' ', "\t", "\n", "\r"]);

            $curPos = ftell($this->inputFileHandler);
            fseek($this->inputFileHandler, $pos, SEEK_SET);
            return ['size' => $curPos - $pos, 'null' => false];
        }

        // String
        if ($firstByte === '"' || $firstByte === "'") {
            $stringDelimiter = $firstByte;
            $end = false;
            while (!feof($this->inputFileHandler) && !$end) {
                $char = fgetc($this->inputFileHandler);

                // Is an escape char: read the next
                if ($char === '\\') {
                    fgetc($this->inputFileHandler);
                }

                $end = $char === $stringDelimiter;
            }
            if (feof($this->inputFileHandler)) {
                throw new \RuntimeException(sprintf('Failed to detect the end of a string value at position %d', $pos));
            }
            $this->skipChars([' ', "\t", "\n", "\r"]);

            $curPos = ftell($this->inputFileHandler);
            fseek($this->inputFileHandler, $pos, SEEK_SET);

            return ['size' => $curPos - $pos, 'null' => false];
        }

        // NULL
        if ($this->nextStringIs('ULL')) {
            $this->skipString('ULL');
            $this->skipChars([' ', "\t", "\n", "\r"]);

            $curPos = ftell($this->inputFileHandler);
            fseek($this->inputFileHandler, $pos, SEEK_SET);

            return ['size' => $curPos - $pos, 'null' => true];
        }

        throw new \RuntimeException(sprintf('Unexpected value at position %d: "%s" (expected a number, a string or NULL)', $pos, $firstByte));
    }

    /**
     * Checks whether the next bytes of the file handler is equal to a string
     * @param string $str
     * @return bool
     */
    private function nextStringIs(string $str): bool
    {
        $pos = ftell($this->inputFileHandler);
        $read = fread($this->inputFileHandler, strlen($str));
        fseek($this->inputFileHandler, $pos, SEEK_SET);

        return $read === $str;
    }

    /**
     * Generates a notification on STDERR (to avoid conflicts with STDOUT)
     * @param string $format Format used by printf
     * @param ...$args Args used by printf
     * @return void
     */
    private function notify(string $format, ...$args): void
    {
        if (!$this->quiet) {
            vfprintf(STDERR, $format, $args);
        }
    }
}
