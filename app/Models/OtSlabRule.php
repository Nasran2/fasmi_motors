<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtSlabRule extends Model
{
    protected $table = 'ot_slab_rules';

    protected $guarded = [];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
