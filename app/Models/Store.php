<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use \App\Traits\ShopScoped;

    protected $guarded = [];

    public function stocks()
    {
        return $this->hasMany(StoreStock::class);
    }
}
