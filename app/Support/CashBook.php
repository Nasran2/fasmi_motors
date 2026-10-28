<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountTransaction;
use Illuminate\Database\Eloquent\Builder;

final class CashBook
{
    /** @return Builder<AccountTransaction> */
    public static function openingEntries(): Builder
    {
        $mainId = Account::systemAccount('main')->id;
        // Opening-balance setup uses a ledger entry rather than always updating accounts.opening_balance.
        // Its compensating reversal has no category, but retains the unique original transaction number.
        $reversals = AccountTransaction::where('account_id', $mainId)->where('category', 'opening_balance')
            ->selectRaw("CONCAT('Reversal of ', transaction_no)");

        return AccountTransaction::posted()->where('account_id', $mainId)->where(fn ($q) => $q
            ->where('category', 'opening_balance')
            ->orWhere(fn ($q) => $q->where('transaction_type', 'reversal')->whereIn('description', $reversals)));
    }

    /** @return Builder<AccountTransaction> */
    public static function query(): Builder
    {
        return AccountTransaction::posted()->where(fn ($q) => $q->ledger('cash')
            ->orWhereIn('id', self::openingEntries()->select('id')));
    }
}
