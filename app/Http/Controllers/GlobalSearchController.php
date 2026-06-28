<?php

namespace App\Http\Controllers;

use App\Models\Bike;
use App\Models\Customer;
use App\Models\JobCard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): View
    {
        $term = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']])['q']);

        return view('search.index', [
            'term' => $term,
            'bikes' => Bike::with('customer')->where(fn ($query) => $query->where('number', 'like', "%{$term}%")->orWhere('model', 'like', "%{$term}%"))->limit(15)->get(),
            'customers' => Customer::where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))->limit(15)->get(),
            'jobs' => JobCard::with('intake.bike.customer')->where('number', 'like', "%{$term}%")->limit(15)->get(),
        ]);
    }
}
