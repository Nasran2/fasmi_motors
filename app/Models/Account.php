<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use ShopScoped;

    protected $guarded = [];

    public static function systemCode(string $accountType, ?string $shopMode = null): ?string
    {
        $shopMode ??= session('shop_mode', 'workshop');
        $prefix = $shopMode === 'helmet' ? 'HELMET_' : '';

        return match ($accountType) {
            'main', 'cash' => $prefix.'MAIN',
            'spare_parts' => $prefix.'SPARE',
            'bank' => $prefix.'BANK',
            default => null,
        };
    }

    public static function ensureSystemAccounts(?string $shopMode = null): void
    {
        $shopMode ??= session('shop_mode', 'workshop');
        foreach ([
            'main' => 'Main Account',
            'spare_parts' => 'Spare Parts Account',
            'bank' => 'Bank Account',
        ] as $type => $name) {
            $code = self::systemCode($type, $shopMode);
            self::withoutGlobalScopes()->firstOrCreate(
                ['code' => $code],
                ['name' => ($shopMode === 'helmet' ? 'Helmet Shop ' : '').$name, 'type' => $type, 'active' => true, 'opening_balance' => 0, 'shop_type' => $shopMode],
            );
        }
    }

    public static function systemAccount(string $accountType): ?self
    {
        self::ensureSystemAccounts();

        return self::where('code', self::systemCode($accountType))->first();
    }

    public function transactions()
    {
        return $this->hasMany(AccountTransaction::class);
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->opening_balance
            + (float) $this->transactions()->posted()->sum('credit')
            - (float) $this->transactions()->posted()->sum('debit');
    }
}
