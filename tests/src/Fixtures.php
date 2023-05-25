<?php

namespace Tests\Printi;

/**
 * Utility methods for unit tests
 */
class Fixtures
{
    /**
     * Create a file with a specific mode and content
     * @param string $mode Mode for opening the file
     * @param bool $existingFile Whether the file should exist when calling fopen
     * @param string $content
     * @return array Associative array with:
     *   string 'file' Path to file
     *   string 'mode' File mode
     *   resource 'stream' File Stream
     */
    public static function openFile(string $mode, bool $existingFile = true, string $content = ''): mixed
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

        if ($content !== '') {
            fwrite($record['stream'], $content);
            fseek($record['stream'], 0, SEEK_SET);
        }

        return $record;
    }

}
