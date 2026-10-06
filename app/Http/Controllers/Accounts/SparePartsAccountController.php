<?php

namespace App\Http\Controllers\Accounts;

use App\Models\AccountTransaction;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SparePartsAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $d = $this->ledgerData($request, 'spare_parts');
        $p = $d['periodTransactions'];
        $sales = (float) $p->where('transaction_type', 'income')->sum('credit');
        $cost = (float) InvoiceItem::whereHas('invoice', fn ($q) => $q->whereBetween('created_at', [$d['from'], $d['to']])->where('status', '!=', 'Cancelled'))->sum(DB::raw('(quantity-returned_quantity)*cost_price'));
        $d += ['title' => 'Spare Parts Ledger', 'subtitle' => 'Parts sales, purchase payments, drawings, and spare parts account balance', 'type' => 'spare_parts', 'cards' => ['Opening Balance' => $d['totals']['opening'], 'Parts Sales' => $sales, 'Parts Cost' => $cost, 'Gross Profit' => $sales - $cost, 'Purchase Payments' => $p->whereIn('transaction_type', ['purchase_payment', 'supplier_payment'])->sum('debit'), 'Drawings' => $p->where('transaction_type', 'drawing')->sum('debit'), 'Transfer In' => $d['totals']['transfer_in'], 'Transfer Out' => $d['totals']['transfer_out'], 'Closing Balance' => $d['totals']['closing']], 'extra' => ['Stock Value at Cost' => Product::join('store_stocks', 'products.id', '=', 'store_stocks.product_id')->sum(DB::raw('store_stocks.quantity*products.cost_price')), 'Stock Value at Selling Price' => Product::join('store_stocks', 'products.id', '=', 'store_stocks.product_id')->sum(DB::raw('store_stocks.quantity*products.selling_price'))]];

        return view('accounts.ledger', $d);
    }

    public function reverse(Request $r, AccountTransaction $transaction, AccountService $s)
    {
        $r->validate(['reason' => 'required|min:5|max:500']);
        abort_unless($transaction->account_type === 'spare_parts', 403);
        abort_if($transaction->reference_type !== null, 422, 'Cancel the source record to reverse its ledger and related balances together.');
        $s->reverseTransaction($transaction, $r->reason);

        return back()->with('success', 'Transaction reversed with a compensating entry.');
    }
}
