<?php

namespace App\Http\Controllers;

use App\Services\WorkshopNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkshopNotificationController extends Controller
{
    public function clear(Request $request, WorkshopNotificationService $notifications)
    {
        $data = $request->validate([
            'keys' => ['required', 'array', 'max:10000'],
            'keys.*' => ['required', 'string', 'size:64'],
        ]);
        $user = $request->user();
        // Only clear notifications this user can currently see, from the submitted snapshot.
        $keys = array_intersect($notifications->summary($user)['keys'], $data['keys']);
        $timestamp = now();
        foreach (array_chunk(array_unique($keys), 500) as $chunk) {
            DB::table('workshop_notification_dismissals')->insertOrIgnore(array_map(fn (string $key) => [
                'user_id' => $user->id, 'notification_key' => $key,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ], $chunk));
        }

        return back()->with('success', 'Notifications cleared.');
    }
}
