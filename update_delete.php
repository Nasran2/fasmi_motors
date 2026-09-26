<?php
$file = 'resources/views/jobs/show.blade.php';
$content = file_get_contents($file);

// Replace the inline form with a button that triggers the modal
$content = preg_replace(
    "/<form method=\"POST\" action=\"\{\{ route\('jobs.parts.destroy', \['job' => \\\$job->id, 'part' => \\\$part->id\]\) \}\}\" class=\"inline-block\" onsubmit=\"return confirm\('Are you sure you want to delete this part from the job card\?'\);\">\s*@csrf\s*@method\('DELETE'\)\s*<button type=\"submit\" class=\"text-red-500[^\"]+\" title=\"Delete part\">\s*<svg[^>]+>.*?<\/svg>\s*<\/button>\s*<\/form>/s",
    '<button type="button" onclick="confirmDeletePart(\'{{ $part->id }}\')" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-1.5 rounded font-bold transition-colors" title="Delete part">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                            </button>',
    $content
);

$deleteDialog = <<<'DIALOG'
<dialog id="delete-part-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 24rem !important;">
    <div class="p-6 text-center space-y-4">
        <div class="mx-auto flex items-center justify-center size-12 rounded-full bg-red-100 mb-4">
            <svg class="size-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h3 class="text-lg font-bold text-slate-900" style="color: #0f172a !important;">Remove Part?</h3>
        <p class="text-sm text-slate-500" style="color: #64748b !important;">Are you sure you want to remove this part from the job card? This action cannot be undone.</p>
        <form id="delete-part-form" method="POST" action="" class="mt-6 flex justify-center gap-3">
            @csrf
            @method('DELETE')
            <button type="button" class="btn-secondary text-sm px-4" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('delete-part-dialog').close()">Cancel</button>
            <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700 text-sm px-4 shadow-red-200" style="background-color: #dc2626 !important;">Yes, Remove it</button>
        </form>
    </div>
</dialog>
DIALOG;

// Add dialog right before </body> or just before <dialog id="unlock-job-dialog">
$content = str_replace('<dialog id="unlock-job-dialog"', $deleteDialog . "\n<dialog id=\"unlock-job-dialog\"", $content);

$js = <<<'JS'
function confirmDeletePart(partId) {
    const dialog = document.getElementById('delete-part-dialog');
    const form = document.getElementById('delete-part-form');
    const route = "{{ route('jobs.parts.destroy', ['job' => $job->id, 'part' => ':part']) }}";
    form.action = route.replace(':part', partId);
    dialog.showModal();
}
JS;

$content = str_replace('function openPartModal', $js . "\n\nfunction openPartModal", $content);

file_put_contents($file, $content);
echo "Done";
