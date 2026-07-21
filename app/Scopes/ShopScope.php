<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class ShopScope implements Scope
{
    public function apply(Builder $builder, Model $model)
    {
        if (request()->hasSession()) {
            $shop = session('shop_mode', 'workshop');
            $builder->where($model->getTable() . '.shop_type', $shop);
        }
    }
}
