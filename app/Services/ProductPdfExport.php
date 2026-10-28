<?php

namespace App\Services;

use App\Models\Product;
use App\Support\PrintDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\FontMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ProductPdfExport
{
    public const PORTRAIT_PER_PAGE = 50;

    public const LANDSCAPE_PER_PAGE = 30;

    public const RECOMMENDED_PAGES = 20;

    public const COLUMNS = [
        'name' => ['Product Name', 28], 'code' => ['SKU / Barcode', 20],
        'rack_number' => ['Rack No', 9], 'brand' => ['Brand', 12], 'qty' => ['Quantity', 10],
        'cost' => ['Cost (Rs.)', 10], 'sell' => ['Sell (Rs.)', 10], 'status' => ['Status', 12],
    ];

    public function query(Request $request): Builder
    {
        return Product::query()
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($x) => $x
                ->where('name', 'like', '%'.$request->q.'%')->orWhere('code', 'like', '%'.$request->q.'%')
                ->orWhere('barcode', 'like', '%'.$request->q.'%')->orWhere('rack_number', 'like', '%'.$request->q.'%')))
            ->when($request->filled('rack_number'), fn ($q) => $q->where('rack_number', 'like', '%'.$request->rack_number.'%'))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->brand))
            ->when($request->status === 'low', fn ($q) => $q->whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock'));
    }

    public function sortedQuery(Request $request): Builder
    {
        $query = $this->query($request)->select(['products.id', 'name', 'code', 'barcode', 'rack_number', 'brand', 'unit', 'cost_price', 'selling_price', 'minimum_stock'])->withSum('stocks', 'quantity');

        return match ($request->input('sort', 'name')) {
            'qty_asc' => $query->orderByRaw('coalesce(stocks_sum_quantity,0) asc')->orderBy('name')->orderBy('products.id'),
            'qty_desc' => $query->orderByRaw('coalesce(stocks_sum_quantity,0) desc')->orderBy('name')->orderBy('products.id'),
            'rack_number' => $query->orderBy('rack_number')->orderBy('name')->orderBy('products.id'),
            default => $query->orderBy('name')->orderBy('code')->orderBy('products.id'),
        };
    }

    public function perPage(array $columns): int
    {
        return count($columns) > 4 ? self::LANDSCAPE_PER_PAGE : self::PORTRAIT_PER_PAGE;
    }

    public function summary(int $total): array
    {
        $columns = array_keys(self::COLUMNS);
        $perPage = $this->perPage($columns);

        return ['total' => $total, 'pages' => (int) ceil($total / $perPage), 'perPage' => $perPage,
            'portraitPerPage' => self::PORTRAIT_PER_PAGE, 'landscapePerPage' => self::LANDSCAPE_PER_PAGE,
            'columns' => $columns, 'recommendedPages' => self::RECOMMENDED_PAGES];
    }

    public function render(Collection $products, array $columns, int $from, int $to, int $total): string
    {
        // Draw bounded pages directly: a single large HTML table exhausts Dompdf's cell map.
        $landscape = count($columns) > 4;
        $perPage = $this->perPage($columns);
        $pdf = Pdf::loadHTML('<!DOCTYPE html><html><body></body></html>')->setPaper('a4', $landscape ? 'landscape' : 'portrait');
        $pdf->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isFontSubsettingEnabled' => true]);
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('DejaVu Sans', 'normal');
        $bold = $metrics->getFont('DejaVu Sans', 'bold');
        $width = $canvas->get_width();
        $height = $canvas->get_height();
        $margin = 22;
        $weights = array_sum(array_map(fn ($column) => self::COLUMNS[$column][1], $columns));
        $rowHeight = $landscape ? 16 : 14.5;
        $pages = (int) ceil($total / $perPage);
        $identity = PrintDocument::configuration();
        $ink = [0.12, 0.18, 0.28];
        $line = [0.84, 0.87, 0.91];
        foreach ($products->chunk($perPage)->values() as $index => $rows) {
            if ($index > 0) {
                $canvas->new_page();
            }
            $page = $from + $index;
            $first = ($page - 1) * $perPage + 1;
            $last = $first + $rows->count() - 1;
            $canvas->text($margin, 15, $identity['business'].' | Products & Stock', $bold, 14, $ink);
            $canvas->text($margin, 36, 'Products '.$first.'-'.$last.' of '.$total.' | Export pages '.$from.'-'.$to.' | '.now()->format('d M Y, H:i'), $font, 7.5, $ink);
            $canvas->filled_rectangle($margin, 52, $width - 2 * $margin, 20, [0.92, 0.94, 0.97]);
            $x = $margin;
            foreach ($columns as $column) {
                $cellWidth = ($width - 2 * $margin) * self::COLUMNS[$column][1] / $weights;
                $canvas->text($x + 4, 57, self::COLUMNS[$column][0], $bold, 7.5, $ink);
                $x += $cellWidth;
            }
            $y = 72;
            foreach ($rows as $rowIndex => $product) {
                if ($rowIndex % 2 === 1) {
                    $canvas->filled_rectangle($margin, $y, $width - 2 * $margin, $rowHeight, [0.97, 0.98, 0.99]);
                }
                $x = $margin;
                foreach ($columns as $column) {
                    $cellWidth = ($width - 2 * $margin) * self::COLUMNS[$column][1] / $weights;
                    [$lines, $size] = $this->fitCell($this->value($product, $column), $cellWidth - 8, $rowHeight - 2, $font, $metrics);
                    foreach ($lines as $lineIndex => $text) {
                        $textX = $x + 4;
                        if (in_array($column, ['cost', 'sell'], true)) {
                            $textX = $x + $cellWidth - 4 - $metrics->getTextWidth($text, $font, $size);
                        }
                        $canvas->text($textX, $y + 1 + $lineIndex * ($size + 1), $text, $font, $size, $ink);
                    }
                    $x += $cellWidth;
                }
                $y += $rowHeight;
                $canvas->line($margin, $y, $width - $margin, $y, $line, 0.4);
            }
            $canvas->text($margin, $height - 20, 'A4 | Page '.$page.' of '.$pages.' | '.$perPage.' products per page', $font, 7.5, $ink);
        }

        return $pdf->output();
    }

    private function value(Product $product, string $column): string
    {
        $qty = (float) ($product->stocks_sum_quantity ?? 0);

        return match ($column) {
            'name' => $product->name,
            'code' => $product->code.($product->barcode && $product->barcode !== $product->code ? ' / '.$product->barcode : ''),
            'qty' => number_format($qty, 2).' '.$product->unit,
            'cost' => number_format((float) $product->cost_price, 2),
            'sell' => number_format((float) $product->selling_price, 2),
            'status' => $qty <= 0 ? 'Out of stock' : ($qty <= $product->minimum_stock ? 'Low stock' : 'In stock'),
            default => $product->$column ?: '-',
        };
    }

    private function fitCell(string $text, float $width, float $height, string $font, FontMetrics $metrics): array
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: '-';
        for ($size = 8.5; $size >= 2; $size -= 0.5) {
            $lines = [];
            $line = '';
            foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $character) {
                if ($line !== '' && $metrics->getTextWidth($line.$character, $font, $size) > $width) {
                    // Prefer word boundaries, while also fitting long SKUs without losing text.
                    $space = mb_strrpos($line, ' ');
                    if ($space !== false && $space > 0) {
                        $lines[] = mb_substr($line, 0, $space);
                        $line = mb_substr($line, $space + 1).$character;
                    } else {
                        $lines[] = $line;
                        $line = $character;
                    }
                } else {
                    $line .= $character;
                }
            }
            $lines[] = trim($line);
            if (count($lines) * ($size + 1) <= $height) {
                return [$lines, $size];
            }
        }

        return [$lines, 2];
    }
}
