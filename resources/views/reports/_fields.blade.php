@php($report = str_replace('reports.', '', request()->route()->getName()))
@foreach($reportFields as $field)
<label>
  <span class="label">{{ $field['label'] }}</span>
  <select name="{{ $field['name'] }}" class="field {{ $field['searchable'] ? 'tom-select' : '' }}" @if($field['searchable']) data-search-filter data-placeholder="Search {{ strtolower($field['label']) }}…" @endif aria-label="Filter by {{ strtolower($field['label']) }}">
    <option value="">{{ $report === 'salary' && $field['name'] === 'status' ? 'Finalized sheets (exclude drafts)' : ($report === 'daily-closing' && $field['name'] === 'status' ? 'Closed days' : 'All') }}</option>
    @foreach($field['options'] as $key => $label)
      <option value="{{ $key }}" @selected((string) request($field['name']) === (string) $key)>{{ $label }}</option>
    @endforeach
  </select>
</label>
@endforeach
@if($report === 'stock')
  <label><span class="label">Stock status</span><select name="status"><option value="">All stock</option>@foreach(['in'=>'In stock','low'=>'Low stock','out'=>'Out of stock'] as $key=>$label)<option value="{{ $key }}" @selected(request('status') === $key)>{{ $label }}</option>@endforeach</select></label>
  <label><span class="label">Sort by</span><select name="sort"><option value="name">Product name</option><option value="rack" @selected(request('sort') === 'rack')>Rack number</option></select></label>
  <label><span class="label">Racks</span><select name="racks[]" multiple class="tom-select" aria-label="Filter by racks">@foreach($rackOptions as $rack)<option value="{{ $rack }}" @selected(in_array($rack, request('racks', [])))>{{ $rack }}</option>@endforeach</select></label>
@endif
@if($report !== 'profit-loss')
<label><span class="label">Search</span><input type="search" name="search" value="{{ request('search') }}" placeholder="{{ match($report) { 'spare-parts', 'stock' => 'Product name or code…', 'index', 'due-bills', 'collections', 'sales-returns' => 'Invoice, bike, customer, phone…', 'job-cards' => 'Job, bike, customer…', 'expenses' => 'Description, vendor, reference…', 'drawings' => 'Person, reference, note…', 'purchases', 'supplier-dues' => 'Bill number or supplier…', 'daily-closing' => 'Closing date (YYYY-MM-DD)…', 'sms' => 'Phone or recipient…', 'cash-book' => 'Receipt, reference, description…', default => 'Search records…' } }}"></label>
@endif
