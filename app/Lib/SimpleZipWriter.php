<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Gera ZIP (deflate) em memória sem depender da extensão ZipArchive (ausente na imagem Docker do Render).
 */
class SimpleZipWriter
{
    /** @var list<array{name: string, crc: int, compSize: int, size: int, offset: int, method: int, time: int, date: int}> */
    private array $entries = [];

    private string $data = '';

    public function addFile(string $name, string $contents): void
    {
        $name = str_replace('\\', '/', ltrim($name, '/'));
        $compressed = gzdeflate($contents, 6);
        $method = 8;
        if ($compressed === false || strlen($compressed) >= strlen($contents)) {
            $compressed = $contents;
            $method = 0;
        }

        [$time, $date] = $this->dosDateTime(time());
        $crc = crc32($contents);
        $offset = strlen($this->data);

        $this->data .= pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0x0800,
            $method,
            $time,
            $date,
            $crc,
            strlen($compressed),
            strlen($contents),
            strlen($name),
            0
        ) . $name . $compressed;

        $this->entries[] = [
            'name' => $name,
            'crc' => $crc,
            'compSize' => strlen($compressed),
            'size' => strlen($contents),
            'offset' => $offset,
            'method' => $method,
            'time' => $time,
            'date' => $date,
        ];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function output(): string
    {
        $central = '';
        foreach ($this->entries as $entry) {
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0x0800,
                $entry['method'],
                $entry['time'],
                $entry['date'],
                $entry['crc'],
                $entry['compSize'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0,
                $entry['offset']
            ) . $entry['name'];
        }

        $end = pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            count($this->entries),
            count($this->entries),
            strlen($central),
            strlen($this->data),
            0
        );

        return $this->data . $central . $end;
    }

    /** @return array{0: int, 1: int} */
    private function dosDateTime(int $timestamp): array
    {
        $d = getdate($timestamp);
        $year = max(1980, (int) $d['year']);

        return [
            ((int) $d['hours'] << 11) | ((int) $d['minutes'] << 5) | intdiv((int) $d['seconds'], 2),
            (($year - 1980) << 9) | ((int) $d['mon'] << 5) | (int) $d['mday'],
        ];
    }
}
