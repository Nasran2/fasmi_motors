<?php

namespace App\Traits;

use App\Scopes\ShopScope;

trait ShopScoped
{
    public static function bootShopScoped()
    {
        static::creating(function ($model) {
            if ($model->shop_type) {
                return;
            }
            if (request()->hasSession()) {
                $model->shop_type = session('shop_mode', 'workshop');
            } else {
                $model->shop_type = 'workshop';
            }
        });

        static::addGlobalScope(new ShopScope);
    }
}
