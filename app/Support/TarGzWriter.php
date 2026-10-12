<?php

namespace App\Support;

use RuntimeException;

/**
 * Writes a .tar.gz one entry at a time, straight to disk.
 *
 * A database backup carries every uploaded file, so the archive is streamed
 * rather than built in memory - and written here rather than through PharData,
 * which rewrites the whole archive on every entry added and keeps archives
 * open in a process-wide cache that Windows will not let go of.
 *
 * Only plain files, in the POSIX ustar layout every tar tool reads. Names up
 * to 255 characters are split across ustar's prefix and name fields.
 * TarGzReader reads back exactly what this writes.
 */
class TarGzWriter
{
    private const BLOCK = 512;

    private const CHUNK = 1048576;

    /** @var resource */
    private $handle;

    private bool $closed = false;

    public function __construct(string $path, int $level = 6)
    {
        $handle = gzopen($path, 'wb'.$level);

        if ($handle === false) {
            throw new RuntimeException('Archive could not be opened for writing.');
        }

        $this->handle = $handle;
    }

    /**
     * Add a file from local disk under the given name.
     */
    public function addFile(string $name, string $localPath): void
    {
        // A caller may rewrite one scratch path for entry after entry, and
        // PHP would otherwise answer with the size it cached for the first.
        clearstatcache(true, $localPath);

        $size = filesize($localPath);
        $source = fopen($localPath, 'rb');

        if ($size === false || $source === false) {
            throw new RuntimeException(sprintf('"%s" could not be read into the archive.', $name));
        }

        try {
            $this->writeHeader($name, $size);

            $written = 0;

            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $this->write($chunk);
                $written += strlen($chunk);
            }
        } finally {
            fclose($source);
        }

        // The header has already promised this many bytes; anything else
        // would shift every entry after it.
        if ($written !== $size) {
            throw new RuntimeException(sprintf('"%s" changed while it was being archived.', $name));
        }

        $this->pad($size);
    }

    public function addString(string $name, string $contents): void
    {
        $this->writeHeader($name, strlen($contents));
        $this->write($contents);
        $this->pad(strlen($contents));
    }

    /**
     * Two empty blocks mark the end of a tar archive.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->write(str_repeat("\0", self::BLOCK * 2));
        gzclose($this->handle);

        $this->closed = true;
    }

    public function __destruct()
    {
        if (! $this->closed) {
            gzclose($this->handle);
        }
    }

    private function writeHeader(string $name, int $size): void
    {
        [$prefix, $base] = self::split($name);

        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $base,
            sprintf('%07o', 0644),
            sprintf('%07o', 0),
            sprintf('%07o', 0),
            sprintf('%011o', $size),
            sprintf('%011o', time()),
            str_repeat(' ', 8),
            '0',
            '',
            'ustar',
            '00',
            '',
            '',
            '',
            '',
            $prefix,
            ''
        );

        // The checksum is the byte sum of the header with its own field read
        // as spaces, which is how it was packed above.
        $checksum = array_sum(unpack('C*', $header));
        $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);

        $this->write($header);
    }

    /**
     * ustar's name field holds 100 bytes; a longer path puts its leading
     * directories in the 155-byte prefix field, split at a slash.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $name): array
    {
        if (strlen($name) <= 100) {
            return ['', $name];
        }

        $offset = 0;

        while (($slash = strpos($name, '/', $offset)) !== false) {
            if ($slash <= 155 && strlen($name) - $slash - 1 <= 100) {
                return [substr($name, 0, $slash), substr($name, $slash + 1)];
            }

            $offset = $slash + 1;
        }

        throw new RuntimeException(sprintf('"%s" is too long a name to archive.', $name));
    }

    private function pad(int $size): void
    {
        $remainder = $size % self::BLOCK;

        if ($remainder > 0) {
            $this->write(str_repeat("\0", self::BLOCK - $remainder));
        }
    }

    private function write(string $bytes): void
    {
        if (gzwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('Archive could not be written.');
        }
    }
}
