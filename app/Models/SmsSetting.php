<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'enabled'            => 'boolean',
        'auto_sms_enabled'   => 'boolean',
        'manual_sms_enabled' => 'boolean',
        'textit_password'    => 'encrypted',
    ];

    /** Get the singleton settings row, creating default if absent. */
    public static function instance(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'provider_name'      => 'Textit.biz',
                'enabled'            => false,
                'api_method'         => 'GET',
                'gateway_url'        => 'https://textit.biz/sendmsg',
                'default_country_code' => '94',
                'id_param'           => 'id',
                'password_param'     => 'pw',
                'to_param'           => 'to',
                'text_param'         => 'text',
                'ref_param'          => 'ref',
                'schedule_param'     => 'schd',
                'success_keyword'    => 'OK',
                'auto_sms_enabled'   => false,
                'manual_sms_enabled' => true,
            ]
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
