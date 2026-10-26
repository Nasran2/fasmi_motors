<dialog id="job-advance-delete-{{ $editingAdvance->id }}" class="m-auto w-[calc(100%-2rem)] max-w-lg max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl" aria-labelledby="advance-delete-title-{{ $editingAdvance->id }}" x-data @if((int) old('_advance_delete_id') === $editingAdvance->id && $errors->has('advance_reason')) x-init="$nextTick(() => $el.showModal())" @endif>
    <form method="POST" action="{{ route('jobs.advances.destroy', [$job, $editingAdvance]) }}" class="space-y-4">
        @csrf @method('DELETE')<x-submission-token/>
        <input type="hidden" name="_advance_delete_id" value="{{ $editingAdvance->id }}">
        <h2 id="advance-delete-title-{{ $editingAdvance->id }}" class="text-xl font-bold">Delete advance</h2>
        <p class="text-sm">{{ $editingAdvance->number }} · Rs. {{ number_format($editingAdvance->amount, 2) }}</p>
        <p class="text-sm text-slate-500">This removes the advance from the balance and reverses its ledger entries. The original receipt and your reason stay in the history.</p>
        <label class="block"><span class="label">Reason for deletion *</span><textarea name="advance_reason" rows="3" minlength="5" maxlength="500" class="field" required>{{ (int) old('_advance_delete_id') === $editingAdvance->id ? old('advance_reason') : '' }}</textarea></label>
        @if((int) old('_advance_delete_id') === $editingAdvance->id) @error('advance_reason')<p class="text-sm text-red-600">{{ $message }}</p>@enderror @endif
        <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" onclick="document.getElementById('job-advance-delete-{{ $editingAdvance->id }}').close()">Cancel</button><button class="btn-primary">Delete advance</button></div>
    </form>
</dialog>
