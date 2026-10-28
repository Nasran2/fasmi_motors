<?php

namespace App\Support;

use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

final class PrintDocument
{
    public static function configuration(): array
    {
        $settings = Setting::whereIn('key', ['business_name', 'business_address', 'business_phone', 'business_email'])->pluck('value', 'key');

        return [
            'business' => ($settings['business_name'] ?? '') ?: 'Fasmir Motors',
            'address' => $settings['business_address'] ?? '',
            'contact' => implode(' | ', array_filter([$settings['business_phone'] ?? '', $settings['business_email'] ?? ''])),
            'shop' => session('shop_mode', 'workshop') === 'helmet' ? 'Helmet Shop' : 'Workshop',
            'generated' => now()->format('d M Y, h:i A'),
            'preparedBy' => auth()->check() ? auth()->user()->name : 'System',
        ];
    }

    public static function pdf(string $view, array $data, string $orientation = 'landscape'): PdfDocument
    {
        $pdf = Pdf::loadView($view, $data + ['pdfMode' => true, 'orientation' => $orientation])->setPaper('a4', $orientation);
        $pdf->render();
        $engine = $pdf->getDomPDF();
        $canvas = $engine->getCanvas();
        $font = $engine->getFontMetrics()->getFont('Helvetica', 'normal');
        $canvas->page_text($canvas->get_width() - 100, $canvas->get_height() - 25, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.35, 0.4, 0.47]);

        return $pdf;
    }

    public static function fromHtml(string $html, array $options = []): array
    {
        $source = new DOMDocument;
        @$source->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        $xpath = new DOMXPath($source);
        $title = $options['title'] ?? self::text($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " page-title ")]')->item(0));
        if (! $title) {
            $title = self::text($xpath->query('//title')->item(0));
        }
        $summary = [];
        foreach ($xpath->query('//*[@data-print-summary]') as $item) {
            $parts = $xpath->query('./*', $item);
            if ($parts->length >= 2) {
                $summary[] = ['label' => self::text($parts->item(0)), 'value' => self::text($parts->item(1))];
            }
        }
        $tables = $xpath->query('//*[@id="reportTable"]');
        if (! $tables->length) {
            $tables = $xpath->query('//*[@data-flux-main]//table');
        }
        $sections = [];
        $columns = 0;
        foreach ($tables as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }
            $headers = $xpath->query('.//thead/tr[1]/*', $table);
            $columns = max($columns, $headers->length);
            $sections[] = ['heading' => '', 'html' => self::table($table)];
        }

        return self::configuration() + [
            'title' => $title ?: 'Report',
            'period' => $options['period'] ?? '',
            'filters' => self::text($xpath->query('//*[@data-print-filters]')->item(0)),
            'summary' => $summary,
            'sections' => $sections,
            'orientation' => $columns > 7 ? 'landscape' : 'portrait',
            'columnCount' => $columns,
        ];
    }

    public static function table(DOMElement $source): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $table = $doc->createElement('table');
        $table->setAttribute('class', 'document-table');
        $doc->appendChild($table);
        $xpath = new DOMXPath($source->ownerDocument);
        $remove = [];
        foreach ($xpath->query('.//thead/tr[1]/*', $source) as $index => $header) {
            if (preg_match('/^(actions?|options?|manage)$/i', self::text($header))) {
                $remove[] = $index;
            }
        }
        $headers = [];
        foreach ($xpath->query('.//thead/tr[1]/*', $source) as $index => $header) {
            if (! in_array($index, $remove, true)) {
                $headers[] = self::text($header);
            }
        }
        if ($headers) {
            if (count($headers) > 7) {
                $table->setAttribute('class', 'document-table document-wide');
            }
            $weights = array_map(self::columnWeight(...), $headers);
            $columns = $doc->createElement('colgroup');
            foreach ($weights as $weight) {
                $column = $doc->createElement('col');
                $column->setAttribute('style', 'width:'.round(100 * $weight / array_sum($weights), 3).'%');
                $columns->appendChild($column);
            }
            $table->appendChild($columns);
        }
        // Final balances belong to the end of the document, not a repeating page footer.
        foreach (['thead', 'tbody', 'tfoot'] as $tag) {
            foreach ($xpath->query('./'.$tag, $source) as $group) {
                $output = $doc->createElement($tag === 'tfoot' ? 'tbody' : $tag);
                if ($tag === 'tfoot') {
                    $output->setAttribute('class', 'document-totals');
                }
                foreach ($xpath->query('./tr', $group) as $row) {
                    $result = $doc->createElement('tr');
                    foreach ($xpath->query('./th|./td', $row) as $index => $cell) {
                        if (in_array($index, $remove, true)) {
                            continue;
                        }
                        $clean = $doc->createElement($cell->nodeName);
                        if ($cell instanceof DOMElement) {
                            foreach (['colspan', 'rowspan'] as $attribute) {
                                if ($cell->hasAttribute($attribute)) {
                                    $clean->setAttribute($attribute, (string) max(1, min(100, (int) $cell->getAttribute($attribute))));
                                }
                            }
                            if (str_contains($cell->getAttribute('class'), 'text-right') || preg_match('/^(?:Rs\.\s*)?-?[\d,]+(?:\.\d+)?%?$/u', self::text($cell))) {
                                $clean->setAttribute('class', 'number');
                            }
                        }
                        self::copyContent($cell, $clean, $doc);
                        $result->appendChild($clean);
                    }
                    $output->appendChild($result);
                }
                $table->appendChild($output);
            }
        }

        return $doc->saveHTML($table);
    }

    private static function columnWeight(string $label): float
    {
        return match (true) {
            preg_match('/^(entry|#)$/i', $label) === 1 => 0.6,
            preg_match('/description|details/i', $label) === 1 => 4.0,
            preg_match('/customer|supplier|product|technician/i', $label) === 1 => 2.6,
            preg_match('/reference|invoice no|number/i', $label) === 1 => 2.2,
            preg_match('/date|recorded/i', $label) === 1 => 1.7,
            preg_match('/amount|total|balance|cost|price|paid|held|due|salary|epf|etf|cash (in|out)/i', $label) === 1 => 2.0,
            default => 1.4,
        };
    }

    private static function copyContent(DOMNode $source, DOMNode $target, DOMDocument $doc): void
    {
        foreach ($source->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $target->appendChild($doc->createTextNode(str_replace(['−', '–', '—', '‑'], '-', $node->textContent)));
            } elseif ($node instanceof DOMElement && ! in_array($node->tagName, ['script', 'style', 'button', 'form', 'input', 'select', 'textarea', 'svg'], true)) {
                $tag = in_array($node->tagName, ['strong', 'b', 'br', 'small', 'p', 'div'], true) ? $node->tagName : 'span';
                $clean = $doc->createElement($tag === 'div' ? 'p' : $tag);
                $classes = [];
                if (str_contains($node->getAttribute('class'), 'text-xs')) {
                    $classes[] = 'muted';
                }
                if (str_contains($node->getAttribute('class'), 'font-bold') || str_contains($node->getAttribute('class'), 'font-black')) {
                    $classes[] = 'strong';
                }
                if ($classes) {
                    $clean->setAttribute('class', implode(' ', $classes));
                }
                self::copyContent($node, $clean, $doc);
                $target->appendChild($clean);
            }
        }
    }

    private static function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
