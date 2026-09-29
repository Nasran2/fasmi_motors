<?php

namespace App\Http\Controllers;

use App\Models\Bike;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Store;
use App\Support\InvoiceNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccidentQuotationController extends Controller
{
    public function create(): View
    {
        $storeName = session('shop_mode', 'workshop') === 'helmet' ? 'Helmet Store' : 'Main Store';
        Store::firstOrCreate(['name' => $storeName], ['is_default' => true, 'active' => true]);

        return view('sales.accident-quotation-create', [
            'customers' => Customer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'bikes' => Bike::query()->orderBy('number')->get(['id', 'customer_id', 'number', 'model', 'brand', 'color']),
            'stores' => Store::query()->where('active', true)->orderByDesc('is_default')->get(['id', 'name']),
            'products' => Product::query()->where('active', true)->orderBy('name')
                ->get(['id', 'name', 'code', 'selling_price'])
                ->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'code' => $product->code,
                    'price' => (float) $product->selling_price,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['bike_number' => Str::upper(trim((string) $request->input('bike_number', '')))]);
        $data = $request->validate([
            'customer_mode' => ['required', Rule::in(['existing', 'new'])],
            'customer_id' => ['nullable', 'required_if:customer_mode,existing', 'integer'],
            'customer_name' => ['nullable', 'required_if:customer_mode,new', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'required_if:customer_mode,new', 'string', 'max:30'],
            'customer_address' => ['nullable', 'string', 'max:500'],
            'bike_mode' => ['required', Rule::in(['existing', 'new'])],
            'bike_id' => ['nullable', 'required_if:bike_mode,existing', 'integer'],
            'bike_number' => ['nullable', 'required_if:bike_mode,new', 'string', 'max:30'],
            'bike_model' => ['nullable', 'required_if:bike_mode,new', 'string', 'max:100'],
            'bike_brand' => ['nullable', 'string', 'max:100'],
            'bike_color' => ['nullable', 'string', 'max:50'],
            'store_id' => ['required', 'integer'],
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:1000'],
            'items.*.unit_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'charges' => ['nullable', 'array', 'max:100'],
            'charges.*.type' => ['required', Rule::in(['service', 'labor', 'washing', 'other'])],
            'charges.*.description' => ['required', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'customer_note' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $items = collect($data['items'] ?? []);
        $charges = collect($data['charges'] ?? []);
        if ($items->isEmpty() && $charges->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one product or charge.']);
        }

        $invoice = DB::transaction(function () use ($data, $items, $charges, $request): Invoice {
            $store = Store::query()->where('active', true)->find($data['store_id']);
            if (! $store) {
                throw ValidationException::withMessages(['store_id' => 'Select an active store.']);
            }

            if ($data['customer_mode'] === 'existing') {
                $customer = Customer::query()->where('active', true)->find($data['customer_id']);
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Select an active customer.']);
                }
            } else {
                $customer = Customer::create([
                    'name' => trim($data['customer_name']),
                    'phone' => trim($data['customer_phone']),
                    'address' => $data['customer_address'] ?? null,
                    'customer_type' => 'Normal Customer',
                    'active' => true,
                ]);
            }

            if ($data['bike_mode'] === 'existing') {
                $bike = Bike::query()->find($data['bike_id']);
                if (! $bike || (int) $bike->customer_id !== (int) $customer->id) {
                    throw ValidationException::withMessages(['bike_id' => 'Select a bike registered to this customer.']);
                }
            } else {
                if (Bike::withTrashed()->where('number', $data['bike_number'])->exists()) {
                    throw ValidationException::withMessages(['bike_number' => 'This bike number is already registered. Select the existing bike.']);
                }
                $bike = Bike::create([
                    'customer_id' => $customer->id,
                    'number' => $data['bike_number'],
                    'model' => trim($data['bike_model']),
                    'brand' => $data['bike_brand'] ?? null,
                    'color' => $data['bike_color'] ?? null,
                ]);
            }

            $products = Product::query()->where('active', true)
                ->whereIn('id', $items->pluck('product_id')->unique())
                ->get(['id', 'name', 'selling_price', 'cost_price'])
                ->keyBy('id');
            if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
                throw ValidationException::withMessages(['items' => 'One or more products are unavailable in this shop.']);
            }

            $usesLowerPrice = $items->contains(fn (array $item) =>
                (float) $item['unit_price'] < (float) $products[$item['product_id']]->selling_price);
            if (($usesLowerPrice || (float) ($data['discount'] ?? 0) > 0) && ! $request->user()->canAccess('sales.apply_discount')) {
                abort(403, 'You do not have permission to reduce prices or apply a discount.');
            }

            $partsTotal = round($items->sum(fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price']), 2);
            $chargeTotals = $charges->groupBy('type')->map(fn ($rows) => round($rows->sum('amount'), 2));
            $chargeTotal = round($charges->sum('amount'), 2);
            $subtotal = round($partsTotal + $chargeTotal, 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the quotation subtotal.']);
            }
            $total = round($subtotal - $discount, 2);

            $invoice = Invoice::create([
                'number' => InvoiceNumber::next('Quotation'),
                'invoice_type' => 'Quotation',
                'is_accident_quotation' => true,
                'status' => 'Draft',
                'customer_id' => $customer->id,
                'bike_id' => $bike->id,
                'store_id' => $store->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => 0,
                'parts_amount' => $partsTotal,
                'service_amount' => $chargeTotals->get('service', 0),
                'labor_amount' => $chargeTotals->get('labor', 0),
                'washing_amount' => $chargeTotals->get('washing', 0),
                'other_amount' => $chargeTotals->get('other', 0),
                'total' => $total,
                'paid_amount' => 0,
                'due_amount' => 0,
                'customer_note' => $data['customer_note'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'public_token' => Str::random(48),
                'link_expires_at' => now()->addDays(90),
                'created_by' => $request->user()->id,
            ]);

            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                $lineTotal = round((float) $item['quantity'] * (float) $item['unit_price'], 2);
                $invoice->items()->create([
                    'product_id' => $product->id,
                    'description' => trim((string) ($item['description'] ?? '')) ?: null,
                    'store_id' => $store->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => 0,
                    'total' => $lineTotal,
                    'cost_price' => $product->cost_price,
                    'profit' => 0,
                ]);
            }

            foreach ($charges as $index => $charge) {
                $invoice->charges()->create([
                    'type' => $charge['type'],
                    'description' => trim($charge['description']),
                    'amount' => $charge['amount'],
                    'sort_order' => $index + 1,
                ]);
            }

            return $invoice;
        });

        $destination = $request->user()->canAccess('sales.view_invoices')
            ? route('sales.quotations')
            : route('invoices.public', $invoice->public_token);

        return redirect($destination)->with('success', "Quotation {$invoice->number} created.");
    }
}
