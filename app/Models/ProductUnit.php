<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    use ShopScoped;

    protected $guarded = [];
}
