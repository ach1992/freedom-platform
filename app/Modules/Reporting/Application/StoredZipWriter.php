<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use DomainException;

final class StoredZipWriter
{
    /** @var list<array{name:string,crc:int,size:int,offset:int}> */
    private array $entries = [];

    private string $body = '';

    public function add(string $name, string $contents): void
    {
        if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, '../')) {
            throw new DomainException('ZIP entry name is unsafe.');
        }
        if (strlen($contents) > 0xFFFFFFFF || strlen($this->body) > 0xFFFFFFFF) {
            throw new DomainException('ZIP32 size limit exceeded.');
        }

        $nameBytes = $name;
        $size = strlen($contents);
        $crc = crc32($contents);
        $offset = strlen($this->body);
        $this->body .= pack(
            'VvvvvvVVVvv',
            0x04034B50,
            20,
            0x0800,
            0,
            0,
            33,
            $crc,
            $size,
            $size,
            strlen($nameBytes),
            0,
        ).$nameBytes.$contents;

        $this->entries[] = ['name' => $nameBytes, 'crc' => $crc, 'size' => $size, 'offset' => $offset];
    }

    public function finish(): string
    {
        if (count($this->entries) > 65_535) {
            throw new DomainException('ZIP32 entry limit exceeded.');
        }

        $centralOffset = strlen($this->body);
        $central = '';
        foreach ($this->entries as $entry) {
            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                0x0800,
                0,
                0,
                33,
                $entry['crc'],
                $entry['size'],
                $entry['size'],
                strlen($entry['name']),
                0,
                0,
                0,
                0,
                0,
                $entry['offset'],
            ).$entry['name'];
        }

        $count = count($this->entries);
        $end = pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            $centralOffset,
            0,
        );

        return $this->body.$central.$end;
    }
}
