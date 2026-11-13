<?php
namespace App\Support;

/** Spreadsheet-safe text, without exporting account credentials or contact data. */
final class StudentReportCsv
{
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return preg_match('/^(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $value) ? "'".$value : $value;
    }
}
