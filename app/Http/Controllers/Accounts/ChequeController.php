<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cheque;
use App\Services\AccountService;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class ChequeController extends Controller
{
    public function index(Request $request)
    {
        $query = Cheque::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('cheque_type', $request->type);
        }

        $cheques = $query->latest('cheque_date')->paginate(30);
        $all = Cheque::all();

        $summary = [
            'received' => $all->where('cheque_type', 'Received')->sum('amount'),
            'issued' => $all->where('cheque_type', 'Issued')->sum('amount'),
            'pending' => $all->where('status', 'Pending')->sum('amount'),
            'cleared' => $all->where('status', 'Cleared')->sum('amount'),
            'returned' => $all->where('status', 'Returned')->sum('amount'),
        ];

        return view('accounts.cheques', compact('cheques', 'summary'));
    }

    public function updateStatus(Request $request, Cheque $cheque, AccountService $service)
    {
        $v = $request->validate([
            'status' => 'required|in:Pending,Deposited,Cleared,Returned,Cancelled',
            'realized_date' => 'required_if:status,Cleared|nullable|date',
        ]);

        // If transitioning to Cleared, record to Bank Ledger
        if ($v['status'] === 'Cleared' && $cheque->status !== 'Cleared') {
            $service->recordTransaction(
                'bank',
                $cheque->cheque_type === 'Received' ? 'income' : 'expense',
                $cheque->amount,
                "Cheque " . $cheque->cheque_number . " cleared.",
                $cheque,
                'cheque',
                "Bank: " . $cheque->bank_name
            );
        }

        // If returned, we should reverse the bank entry if it was cleared before (or handle customer due).
        // For simplicity, we just track status here. Real reversal should use the AccountService reversal logic on the specific transaction.

        $cheque->update([
            'status' => $v['status'],
            'realized_date' => $v['realized_date'] ?? null
        ]);

        return back()->with('success', 'Cheque status updated successfully.');
    }
}
