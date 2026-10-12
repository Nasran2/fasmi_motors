<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\JobCardPart;
use App\Models\Setting;

final class InvoiceDocument
{
    public const QUOTATION_INTRO_HEADING = 'About this quotation';

    public const QUOTATION_INTRO_TEXT = 'This document is an estimate for the listed products and services. It is not a payment receipt.';

    public const BILL_INTRO_HEADING = 'About this bill';

    public const BILL_INTRO_TEXT = 'Please check the items and amounts above. Keep this invoice for your records.';

    public static function data(Invoice $invoice, bool $pdfMode = false, bool $allowPublicPdf = false): array
    {
        $invoice->loadMissing([
            'customer', 'bike', 'store', 'jobCard.intake.bike', 'jobCard.technician',
            'jobCard.parts.product', 'items.product', 'charges', 'payments',
        ]);

        $settings = Setting::query()->whereIn('key', [
            'business_name', 'business_subtitle', 'business_address', 'business_phone',
            'business_email', 'business_website', 'business_registration_no',
            'tax_registration_no', 'invoice_footer_note', 'terms_and_conditions',
            'quotation_intro_heading', 'quotation_intro_text', 'bill_intro_heading', 'bill_intro_text',
            'customer_note_heading', 'quotation_default_note', 'bill_default_note', 'terms_heading',
            'invoice_signature_text', 'show_logo_invoice', 'show_bike_invoice',
            'show_technician_invoice', 'logo_url',
        ])->pluck('value', 'key')->all();
        $name = trim((string) ($settings['business_name'] ?? ''));
        $subtitle = trim((string) ($settings['business_subtitle'] ?? 'Bike Repair Center'));
        if ($subtitle !== '' && str_ends_with(strtolower($name), strtolower($subtitle))) {
            $shortName = trim(substr($name, 0, -strlen($subtitle)));
            if ($shortName !== '') {
                $settings['business_name'] = $shortName;
            }
        }
        if (($settings['business_registration_no'] ?? null) === 'PV-99999') {
            $settings['business_registration_no'] = null;
        }
        if (($settings['tax_registration_no'] ?? null) === 'TIN-12345678') {
            $settings['tax_registration_no'] = null;
        }

        $isQuotation = $invoice->invoice_type === 'Quotation';
        $customerNote = trim((string) ($invoice->customer_note ?: self::wordingValue($settings, $isQuotation ? 'quotation_default_note' : 'bill_default_note')));
        $wording = [
            'introHeading' => self::wordingValue($settings, $isQuotation ? 'quotation_intro_heading' : 'bill_intro_heading', $isQuotation ? self::QUOTATION_INTRO_HEADING : self::BILL_INTRO_HEADING),
            'introText' => self::wordingValue($settings, $isQuotation ? 'quotation_intro_text' : 'bill_intro_text', $isQuotation ? self::QUOTATION_INTRO_TEXT : self::BILL_INTRO_TEXT),
            'customerNoteHeading' => self::wordingValue($settings, 'customer_note_heading', 'Note for customer'),
            'customerNote' => $customerNote,
            'termsHeading' => self::wordingValue($settings, 'terms_heading', 'Terms and conditions'),
            'terms' => self::wordingValue($settings, 'terms_and_conditions'),
            'footerNote' => self::wordingValue($settings, 'invoice_footer_note', 'Thank you for choosing Fasmir Motors.'),
        ];

        $lines = [];
        $parts = in_array($invoice->invoice_type, ['Parts Sale', 'Quotation'], true)
            ? $invoice->items : ($invoice->jobCard?->parts ?? collect())->sortBy(fn ($part) => mb_strtolower(trim($part->name)), SORT_STRING);

        foreach ($parts as $item) {
            $lines[] = [
                'name' => $item instanceof JobCardPart ? $item->bill_name : ($item->description ?: ($item->product?->name ?? 'Product')),
                'detail' => collect([
                    $item->product?->code,
                    (float) ($item->returned_quantity ?? 0) > 0 ? 'Returned: '.$item->returned_quantity : null,
                    (float) ($item->discount ?? 0) > 0 ? 'Line discount Rs. '.number_format((float) $item->discount, 2) : null,
                ])->filter()->implode(' · '),
                'qty' => max(0, (float) $item->quantity - (float) ($item->returned_quantity ?? 0)),
                'rate' => (float) $item->unit_price,
                'total' => (float) $item->total - round((float) $item->total * (float) ($item->returned_quantity ?? 0) / (float) $item->quantity, 2),
            ];
        }

        if (in_array($invoice->invoice_type, ['Parts Sale', 'Quotation'], true)) {
            foreach ($invoice->charges as $charge) {
                $lines[] = [
                    'name' => $charge->description,
                    'detail' => '',
                    'qty' => 1,
                    'rate' => (float) $charge->amount,
                    'total' => (float) $charge->amount,
                ];
            }
        }

        if (! in_array($invoice->invoice_type, ['Parts Sale', 'Quotation'], true)) {
            $service = (float) $invoice->service_amount;
            $labor = (float) $invoice->labor_amount;
            $washing = (float) $invoice->washing_amount;
            if ($invoice->jobCard && ! $invoice->jobCard->show_labor_on_bill) {
                $service += $labor;
                $labor = 0;
            }
            if ($invoice->jobCard && ! $invoice->jobCard->show_washing_on_bill) {
                $service += $washing;
                $washing = 0;
            }
            foreach ([
                [$invoice->jobCard?->service_name ?: 'Service', $service],
                ['Labor', $labor],
                ['Wash', $washing],
                [$invoice->jobCard?->other_income_name ?: 'Other charges', (float) $invoice->other_amount],
            ] as [$name, $amount]) {
                if ($amount > 0) {
                    $lines[] = ['name' => $name, 'detail' => 'Service', 'qty' => 1, 'rate' => $amount, 'total' => $amount];
                }
            }
        }

        $logo = null;
        $logoUrl = (string) ($settings['logo_url'] ?? '');
        if ($logoUrl && str_starts_with($logoUrl, '/storage/') && ($settings['show_logo_invoice'] ?? '1') !== '0') {
            $path = realpath(public_path(ltrim($logoUrl, '/')));
            $storageRoot = realpath(public_path('storage'));
            if ($path && $storageRoot && str_starts_with($path, $storageRoot.DIRECTORY_SEPARATOR)) {
                $mime = mime_content_type($path);
                if (in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    $logo = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
                }
            }
        }

        $showPreviousBalance = ! $isQuotation && (bool) $invoice->jobCard?->show_previous_balance_on_bill;
        $previousBalances = $showPreviousBalance ? PreviousCustomerBalance::forJob($invoice->jobCard, $invoice) : collect();
        $previousBalance = PreviousCustomerBalance::total($previousBalances);

        return [
            'invoice' => $invoice,
            'business' => $settings,
            'lines' => $lines,
            'logo' => $logo,
            'pdfMode' => $pdfMode,
            'allowPublicPdf' => $allowPublicPdf,
            'isQuotation' => $isQuotation,
            'wording' => $wording,
            'showPreviousBalance' => $showPreviousBalance,
            'previousBalances' => $previousBalances,
            'previousBalance' => $previousBalance,
            'grandTotal' => round((float) $invoice->total + $previousBalance, 2),
            'totalBalanceDue' => round((float) $invoice->due_amount + $previousBalance, 2),
        ];
    }

    private static function wordingValue(array $settings, string $key, string $default = ''): string
    {
        return array_key_exists($key, $settings) ? trim((string) $settings[$key]) : $default;
    }
}
