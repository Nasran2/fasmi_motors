<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    protected $guarded = [];

    protected $casts = ['cost' => 'decimal:2'];

    public function reference()
    {
        return $this->morphTo();
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
