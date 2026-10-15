<x-layouts::app title="Edit Parts Sale">
@php($saleLines = old('items', $invoice->items->map(fn($i) => ['id'=>$i->id,'product_id'=>$i->product_id,'quantity'=>(float)$i->quantity,'unit_price'=>(float)$i->unit_price,'discount'=>(float)$i->discount])->values()->all()))
<div class="space-y-5"><div><h1 class="page-title">Edit {{ $invoice->number }}</h1><p class="page-subtitle">Review stock returns, expenses, refunds, and customer balances before saving.</p></div><x-flash/>
<form id="parts-sale-edit-form" data-parts-sale-edit data-preview-url="{{ route('sales.parts.edit-preview', $invoice) }}" method="POST" action="{{ route('sales.parts.update',$invoice) }}" class="space-y-5" x-data="{lines: @js($saleLines), prices: @js($products->pluck('selling_price','id'))}">@csrf @method('PUT')<x-submission-token/><input type="hidden" name="revision_confirmation" value="">
    <section class="panel grid gap-4 sm:grid-cols-2"><label class="text-sm font-bold">Customer<select name="customer_id" class="field mt-2"><option value="">Walk-in Customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id',$invoice->customer_id)==$customer->id)>{{ $customer->name }} · {{ $customer->phone }}</option>@endforeach</select></label><div class="text-sm"><b>Store</b><p class="mt-3">{{ $invoice->store?->name }}</p></div></section>
    <section class="panel space-y-4"><div class="flex justify-between gap-3"><h2 class="font-extrabold">Product Items</h2><button type="button" class="btn-secondary" @click="lines.push({id:null,product_id:'',quantity:1,unit_price:0,discount:0})">＋ Add Product</button></div>
    <template x-for="(line,index) in lines" :key="index"><div class="grid items-end gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto]"><input type="hidden" :name="`items[${index}][id]`" :value="line.id || ''"><label class="text-xs font-bold">Product<select class="field mt-2" :name="`items[${index}][product_id]`" x-model="line.product_id" @change="line.unit_price=prices[line.product_id] || 0; line.id=null" required><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach</select></label><label class="text-xs font-bold">Quantity<input type="number" min="0.01" step="0.01" required class="field mt-2" :name="`items[${index}][quantity]`" x-model="line.quantity"></label><label class="text-xs font-bold">Unit Price<input type="number" min="0" step="0.01" required class="field mt-2" :name="`items[${index}][unit_price]`" x-model="line.unit_price"></label><label class="text-xs font-bold">Line Discount<input type="number" min="0" step="0.01" required class="field mt-2" :name="`items[${index}][discount]`" x-model="line.discount"></label><button type="button" class="btn-secondary !text-red-600" @click="lines.splice(index,1)">Remove</button></div></template>
    @if($invoice->charges->isNotEmpty())<p class="text-sm text-slate-500">Existing service charges: Rs. {{ number_format($invoice->charges->sum('amount'),2) }}</p>@endif</section>
    <section class="panel grid gap-4 sm:grid-cols-2"><label class="text-sm font-bold">Discount (LKR)<input name="discount" type="number" step="0.01" min="0" class="field mt-2" required value="{{ old('discount',$invoice->discount) }}"></label><label class="text-sm font-bold">Tax (LKR)<input name="tax" type="number" step="0.01" min="0" class="field mt-2" required value="{{ old('tax',$invoice->tax) }}"></label><label class="text-sm font-bold">Internal Note<textarea name="internal_note" class="field mt-2">{{ old('internal_note',$invoice->internal_note) }}</textarea></label><label class="text-sm font-bold">Customer Note<textarea name="customer_note" class="field mt-2">{{ old('customer_note',$invoice->customer_note) }}</textarea></label><label class="text-sm font-bold sm:col-span-2">Reason for Editing<textarea name="reason" required minlength="5" maxlength="500" class="field mt-2" placeholder="Explain the correction">{{ old('reason') }}</textarea></label></section>
    <div class="flex justify-end gap-3"><a href="{{ route('sales.invoices.show',$invoice) }}" class="btn-secondary">Cancel</a><button type="submit" data-save-sale class="btn-primary">Save Changes</button></div>
    <dialog id="sale-edit-review" aria-labelledby="sale-edit-review-title" class="m-auto w-[calc(100%-2rem)] max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl backdrop:bg-slate-900/60" style="max-height:90vh;overflow-y:auto">
        <div class="flex items-center justify-between gap-3"><h2 id="sale-edit-review-title" class="text-lg font-extrabold">Review Sale Changes</h2><button type="button" data-close-sale-review class="btn-secondary !px-3" aria-label="Close sale review">✕</button></div>
        <p class="mt-2 text-sm text-slate-500">These changes take effect together when you confirm and save.</p>
        <div data-review-error role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800" hidden></div>
        <div data-review-body>
            <section data-removed-section class="mt-5 space-y-3" hidden><h3 class="font-bold">Removed products / reduced quantities</h3><p class="text-sm text-slate-500">Restock is selected by default. Choose expense for products that cannot go back into stock.</p><div data-removed-items class="mt-3 space-y-3"></div></section>
            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl bg-slate-50 p-3"><span class="text-sm text-slate-500">Revised sale total</span><b data-review-total class="block text-lg"></b></div>
                <div class="rounded-xl bg-slate-50 p-3"><span class="text-sm text-slate-500">Remaining due on this sale</span><b data-review-new-due class="block text-lg"></b></div>
            </div>
            <p data-review-due-reduction class="mt-3 text-sm text-slate-600"></p>
            <p class="mt-3 text-sm">Cost recorded as expense: <b data-review-expense>Rs. 0.00</b></p>
            <section data-refund-section class="mt-5 space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4" hidden>
                <p class="font-bold">Available refund / credit: <span data-review-refund></span></p>
                <p class="text-sm">Refunds use the original payment methods.</p>
                <div data-offset-section hidden>
                    <p data-review-other-due class="text-sm"></p>
                    <label class="mt-3 flex items-start gap-2 text-sm font-bold"><input type="checkbox" name="refund_action" value="offset_due" class="mt-1"><span data-offset-label></span></label>
                </div>
                <p data-review-credit-row class="text-sm" hidden>Applied to customer dues: <b data-review-credit>Rs. 0.00</b></p>
                <p class="text-lg font-extrabold">Give customer: <span data-review-payout></span></p>
            </section>
        </div>
        <div class="mt-6 flex justify-end gap-3"><button type="button" data-close-sale-review class="btn-secondary">Back to Edit</button><button type="button" data-confirm-sale-review class="btn-primary">Confirm &amp; Save</button></div>
    </dialog>
    <template id="sale-edit-removed-template">
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <p class="font-bold"><span data-removed-name></span> × <span data-removed-quantity></span></p>
            <label class="mt-3 block text-sm"><span>Stock action</span><select class="field mt-1"><option value="restock">Restock again</option><option value="expense">Record cost as expense — no restock</option></select></label>
            <p class="mt-2 text-xs text-slate-500">Original cost: <span data-removed-cost></span>. The expense option records stock cost without another cash payment.</p>
        </div>
    </template>
</form></div></x-layouts::app>
