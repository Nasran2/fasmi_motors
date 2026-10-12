<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;

final class ThermalReceipt
{
    public static function pdf(array $data): \Barryvdh\DomPDF\PDF
    {
        // Leave enough room for wrapping product names, contact details, and customer terms.
        $text = ($data['business']['business_address'] ?? '').$data['wording']['customerNote'].$data['wording']['terms'];
        $heightMm = 120 + ceil(mb_strlen($text) / 30) * 4;
        if ($data['invoice']->change_amount > 0) {
            $heightMm += 12;
        }
        if ($data['showPreviousBalance'] ?? false) {
            $heightMm += 26;
            foreach ($data['previousBalances'] as $balance) {
                $heightMm += 12 + ceil(mb_strlen($balance['reference']) / 30) * 4;
            }
        }
        foreach ($data['lines'] as $line) {
            $heightMm += 11 + ceil(mb_strlen($line['name'].$line['detail']) / 30) * 4;
        }

        return Pdf::loadView('invoices.thermal', $data)->setPaper([0, 0, 80 * 72 / 25.4, $heightMm * 72 / 25.4]);
    }
}
