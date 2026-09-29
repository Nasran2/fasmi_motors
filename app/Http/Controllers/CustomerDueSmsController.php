<?php

namespace App\Http\Controllers;

use App\Services\Sms\CustomerDueSmsService;
use Illuminate\Http\Request;

class CustomerDueSmsController extends Controller
{
    public function preview(Request $request, CustomerDueSmsService $sms)
    {
        $data = $request->validate([
            'invoice_ids' => 'required|array|min:1|max:100', 'invoice_ids.*' => 'required|integer|distinct',
            'template' => 'required|string|max:1000',
        ]);

        return response()->json($sms->prepare($data['invoice_ids'], $data['template']));
    }

    public function send(Request $request, CustomerDueSmsService $sms)
    {
        $data = $request->validate(['token' => 'required|uuid', 'recipient_key' => 'required|string|max:80']);

        return response()->json($sms->send($data['token'], $data['recipient_key']));
    }
}
