<?php

namespace App\Support;

use Closure;
use UnexpectedValueException;

/**
 * Reads back what TarGzWriter wrote: plain files in the ustar layout.
 *
 * An archive here may be one somebody uploaded, so nothing is trusted. Each
 * header's checksum is verified, anything but a plain file is refused, a
 * name may appear only once, and an archive that stops before its end marker
 * is damaged rather than short. Nothing is written anywhere the caller has
 * not chosen: the caller is handed each entry's name and decides where - or
 * whether - it lands.
 */
class TarGzReader
{
    private const BLOCK = 512;

    private const CHUNK = 1048576;

    /**
     * Extract every entry, in archive order.
     *
     * @param  Closure(string, int): string  $target  given an entry's name and
     *                                                size, returns the local path to write it to. Throwing
     *                                                refuses the archive.
     * @return array<string, array{path: string, size: int, sha256: string}>
     *
     * @throws UnexpectedValueException when the archive is damaged or holds
     *                                  something other than plain files
     */
    public static function extract(string $archivePath, Closure $target): array
    {
        $handle = @gzopen($archivePath, 'rb');

        if ($handle === false) {
            throw new UnexpectedValueException('Archive could not be opened.');
        }

        $entries = [];

        try {
            while (true) {
                $header = self::read($handle, self::BLOCK);

                if ($header === str_repeat("\0", self::BLOCK)) {
                    break;
                }

                [$name, $size] = self::parseHeader($header);

                if (isset($entries[$name])) {
                    throw new UnexpectedValueException(sprintf('"%s" appears twice.', $name));
                }

                $path = $target($name, $size);
                $entries[$name] = [
                    'path' => $path,
                    'size' => $size,
                    'sha256' => self::copyEntry($handle, $size, $path),
                ];

                $remainder = $size % self::BLOCK;

                if ($remainder > 0) {
                    self::read($handle, self::BLOCK - $remainder);
                }
            }
        } finally {
            gzclose($handle);
        }

        return $entries;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function parseHeader(string $header): array
    {
        if (substr($header, 257, 6) !== "ustar\0") {
            throw new UnexpectedValueException('Not a ustar archive.');
        }

        $stored = trim(substr($header, 148, 8), "\0 ");
        $expected = array_sum(unpack('C*', substr_replace($header, str_repeat(' ', 8), 148, 8)));

        if (! ctype_digit($stored) || octdec($stored) !== $expected) {
            throw new UnexpectedValueException('Archive header checksum mismatch.');
        }

        $type = $header[156];

        if ($type !== '0' && $type !== "\0") {
            throw new UnexpectedValueException('Archive holds something other than plain files.');
        }

        $size = trim(substr($header, 124, 12), "\0 ");

        if (! ctype_digit($size)) {
            throw new UnexpectedValueException('Archive entry size is unreadable.');
        }

        $name = rtrim(substr($header, 0, 100), "\0");
        $prefix = rtrim(substr($header, 345, 155), "\0");

        if ($prefix !== '') {
            $name = $prefix.'/'.$name;
        }

        if ($name === '' || str_contains($name, "\0")) {
            throw new UnexpectedValueException('Archive entry has no usable name.');
        }

        return [$name, (int) octdec($size)];
    }

    /**
     * @param  resource  $handle
     */
    private static function copyEntry($handle, int $size, string $path): string
    {
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new UnexpectedValueException('Archive entry could not be extracted.');
        }

        $hash = hash_init('sha256');

        try {
            $left = $size;

            while ($left > 0) {
                $chunk = self::read($handle, min(self::CHUNK, $left));

                hash_update($hash, $chunk);
                fwrite($out, $chunk);

                $left -= strlen($chunk);
            }
        } finally {
            fclose($out);
        }

        return hash_final($hash);
    }

    /**
     * Exactly $length bytes, or the archive ended early.
     *
     * @param  resource  $handle
     */
    private static function read($handle, int $length): string
    {
        $bytes = '';

        while (strlen($bytes) < $length) {
            $chunk = gzread($handle, $length - strlen($bytes));

            if ($chunk === false || $chunk === '') {
                throw new UnexpectedValueException('Archive ended early.');
            }

            $bytes .= $chunk;
        }

        return $bytes;
    }
}
