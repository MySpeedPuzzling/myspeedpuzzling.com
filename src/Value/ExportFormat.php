<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum ExportFormat: string
{
    case Json = 'json';
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case Xml = 'xml';

    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
            self::Xml => 'application/xml',
        };
    }

    public function fileExtension(): string
    {
        return $this->value;
    }
}
