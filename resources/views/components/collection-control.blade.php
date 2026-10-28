@if(\App\Support\RoutePermission::allows(auth()->user(), 'cashier.collections'))
<a href="{{ route('cashier.collections') }}" target="_blank" rel="noopener" onclick="window.open(this.href, '_blank', 'width=1440,height=900,scrollbars=yes,resizable=yes'); return false;" class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap rounded-xl border-2 border-blue-200 bg-blue-50 px-2 py-2 text-[10px] sm:px-3 sm:text-xs font-black text-blue-900 shadow-sm hover:bg-blue-100" title="View cash and bank collections in a new window">
    <x-dashboard-icon name="accounts" class="hidden size-4 sm:block"/><span class="hidden sm:inline">All Collections</span><span class="sm:hidden">Collections</span>
</a>
@endif
