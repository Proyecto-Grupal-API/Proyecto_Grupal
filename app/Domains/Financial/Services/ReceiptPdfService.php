<?php

namespace App\Domains\Financial\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class ReceiptPdfService
{
    public function cash(array $snapshot): array
    {
        $type = $snapshot['movement_type'];
        $amount = (int) $snapshot['amount_cents'];
        if (in_array($type, ['CASH_OUT', 'WITHDRAWAL'], true)) {
            $amount = -abs($amount);
        }

        return ['id' => $snapshot['id'], 'source' => 'CASH', 'status' => 'REGISTRADA', 'currency' => $snapshot['currency'],
            'created_at' => $snapshot['issued_at'], 'reference_type' => $type, 'original_transaction_id' => $snapshot['original_transaction_id'] ?? null,
            'cash_register_name' => $snapshot['cash_register_name'], 'association_id' => $snapshot['association_id'],
            'entries' => [['movement_type' => $type, 'amount_cents' => $amount, 'created_at' => $snapshot['issued_at']]],
            'purchase' => null, 'cash_receipts' => [['folio' => $snapshot['folio'], 'issued_at' => $snapshot['issued_at']]], 'historical_notice' => null];
    }

    public function download(array $receipt)
    {
        if (! class_exists(Dompdf::class)) {
            abort(503, 'El generador PDF no está instalado.');
        }
        $cache = storage_path('framework/cache/receipt-pdf');
        File::ensureDirectoryExists($cache);
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $root = dirname((new \ReflectionClass(Dompdf::class))->getFileName(), 2);
        $options->set('chroot', [$root]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4', 'portrait');
        $money = static function ($cents, string $currency): string {
            if ($cents === null) {
                return 'Sin registro';
            }
            $s = (string) $cents;
            $negative = str_starts_with($s, '-');
            $s = ltrim($s, '-');
            $s = str_pad($s, 3, '0', STR_PAD_LEFT);
            $whole = substr($s, 0, -2);
            $fraction = substr($s, -2);

            return ($negative ? '-' : '').$currency.' '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
        };
        $date = static fn ($value) => $value ? Carbon::parse($value)->utc()->format('d/m/Y H:i:s').' UTC' : 'Sin registro';
        $html = view('financial.receipt-pdf', ['receipt' => $receipt, 'money' => $money, 'date' => $date, 'generated_at' => now()->toISOString()])->render();
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $id = preg_replace('/[^a-zA-Z0-9-]/', '', (string) $receipt['id']);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Campus-Digital-comprobante-'.$id.'.pdf"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
