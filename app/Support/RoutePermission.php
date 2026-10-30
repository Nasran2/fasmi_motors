<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final class RoutePermission
{
    public static function for(string $routeName, ?string $workspaceSection = null, array $parameters = [], array $query = []): string|array|null
    {
        if ($routeName === 'workspace.page') {
            return config("route-permissions.workspace.{$workspaceSection}");
        }

        if (in_array($routeName, ['products.references', 'products.references.store'], true)) {
            $type = $parameters['type'] ?? $query['tab'] ?? 'categories';

            return match ($type) {
                'brands' => 'product.brands',
                'units' => 'product.units',
                default => 'product.categories',
            };
        }

        if ($routeName === 'service-reminders.page' && ($parameters['page'] ?? null) === 'settings') {
            return 'reminder.settings';
        }

        if (Str::is('finance-module.*', $routeName)) {
            $module = $parameters['module'] ?? 'expense';
            $action = $routeName === 'finance-module.cancel' ? 'cancel' : 'view';
            $permissions = ["{$module}.{$action}"];
            if ($routeName === 'finance-module.export') {
                $permissions[] = ($query['format'] ?? 'pdf') === 'excel' ? 'reports.export_excel' : 'reports.export_pdf';
            }

            return $permissions;
        }

        if ($routeName === 'accounts.export') {
            $viewPermission = match ($parameters['type'] ?? null) {
                'spare_parts' => 'account.view_spare_parts',
                'bank' => 'account.view_bank',
                'cash' => 'account.view_cash_book',
                default => 'account.view_main',
            };

            return [$viewPermission, ($query['format'] ?? 'pdf') === 'excel' ? 'reports.export_excel' : 'reports.export_pdf'];
        }

        foreach (config('route-permissions.routes', []) as $pattern => $permission) {
            if (Str::is($pattern, $routeName)) {
                return $permission;
            }
        }

        return null;
    }

    public static function allows(User $user, string $routeName, ?string $workspaceSection = null, array $parameters = [], array $query = []): bool
    {
        if (in_array($routeName, ['cashier.closing-popup', 'cashier.index', 'cashier.open', 'cashier.close', 'cashier.collections', 'cashier.movements', 'cashier.transaction-source', 'cashier.opening-popup', 'cashier.opening-balances', 'cashier.historical-opening'], true)) {
            return collect(['account.daily_closing', 'job_card.create', 'sales.parts_sales', 'sales.job_card_billing'])
                ->contains(fn ($permission) => $user->canAccess($permission));
        }
        if ($routeName === 'cashier.closing-balances') {
            return $user->canAccess('account.daily_closing');
        }
        if ($routeName === 'cashier.reopen') {
            return $user->canAccess('account.reopen_closing');
        }
        if ($routeName === 'sales.invoices.pdf') {
            return $user->canAccess('sales.view_invoices') || $user->canAccess('sales.parts_sales');
        }

        $permission = self::for($routeName, $workspaceSection, $parameters, $query);

        if ($permission === null) {
            return true;
        }

        return collect((array) $permission)->every(fn (string $value) => $user->canAccess($value));
    }
}
