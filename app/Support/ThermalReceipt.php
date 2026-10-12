<?php

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;

final class ThermalReceipt
{
    public static function pdf(array $data): \Barryvdh\DomPDF\PDF
    {
        // Measure on a generous roll first, then trim the PDF to the rendered receipt.
        $text = implode('', $data['business']).implode('', $data['wording']);
        $heightMm = 180 + ceil(mb_strlen($text) / 25) * 5;
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
            $heightMm += 14 + ceil(mb_strlen($line['name']) / 25) * 5;
        }

        $receiptHeight = 0;
        $pdf = Pdf::loadView('invoices.thermal', [...$data, 'receiptHeightMm' => $heightMm])
            ->setPaper([0, 0, 80 * 72 / 25.4, $heightMm * 72 / 25.4]);
        $pdf->getDomPDF()->setCallbacks([[
            'event' => 'end_frame',
            'f' => static function (AbstractFrameDecorator $frame) use (&$receiptHeight): void {
                if ($frame->get_node()->nodeName === 'main') {
                    $receiptHeight += $frame->get_border_box()['h'];
                }
            },
        ]]);
        $pdf->render();

        // Add both 4 mm page margins and a small allowance for rounding.
        $heightMm = max(80, ceil($receiptHeight * 25.4 / 72) + 10);

        return Pdf::loadView('invoices.thermal', [...$data, 'receiptHeightMm' => $heightMm])
            ->setPaper([0, 0, 80 * 72 / 25.4, $heightMm * 72 / 25.4]);
    }
}
