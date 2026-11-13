<?php
namespace Tests\Unit;
use App\Support\StudentReportCsv;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
final class StudentReportCsvTest extends TestCase
{
    public static function cells(): iterable
    {
        yield ['=1+1', "'=1+1"]; yield [' +cmd', "' +cmd"]; yield ['@SUM(A1)', "'@SUM(A1)"];
        yield ["\tformula", "'\tformula"]; yield ['Estudiante', 'Estudiante']; yield [null, ''];
    }
    #[DataProvider('cells')]
    public function test_csv_cells_are_safe_without_altering_plain_values(mixed $input,string $expected):void
    {self::assertSame($expected,StudentReportCsv::cell($input));}
}
