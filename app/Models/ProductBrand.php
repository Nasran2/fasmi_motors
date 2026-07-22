<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;

class ProductBrand extends Model
{
    use ShopScoped;

    protected $guarded = [];
}
