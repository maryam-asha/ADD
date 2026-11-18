<?php

namespace Add\Hr\Reports\Support;

use Add\Hr\Audit\HrAudit;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A report as a CSV download (UTF-8 with a BOM, so spreadsheets read
 * Arabic correctly). Every export is audited.
 */
final class CsvExport
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<scalar|null>>  $rows
     * @param  array<string, mixed>  $params
     */
    public static function download(string $report, array $params, array $header, iterable $rows): StreamedResponse
    {
        HrAudit::record('report_exported', null, ['report' => $report, ...$params]);

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, str_replace('_', '-', $report).'-'.implode('-', array_values(array_filter($params, 'is_scalar'))).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A bilingual value in the request's language.
     *
     * @param  array<string, string>|null  $value
     */
    public static function label(?array $value): string
    {
        return (string) ($value[app()->getLocale()] ?? $value['en'] ?? $value['ar'] ?? '');
    }
}
