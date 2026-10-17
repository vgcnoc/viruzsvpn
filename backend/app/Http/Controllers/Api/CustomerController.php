<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        return User::query()->where('role', 'customer')
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%")))
            ->withCount(['vpnOrders', 'vpnAccounts'])
            ->latest()->paginate(30, ['id', 'name', 'email', 'email_verified_at', 'created_at']);
    }
}
