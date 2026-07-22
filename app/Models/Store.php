<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;

class Store extends Model
{
    use ShopScoped;

    protected $guarded = [];

    public function stocks()
    {
        return $this->hasMany(StoreStock::class);
    }
}
