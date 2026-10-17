<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Servers\ProtocolRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index()
    {
        return Plan::query()->orderBy('price_cents')->paginate(25);
    }

    public function store(Request $request)
    {
        return response()->json(Plan::create($this->validated($request)), 201);
    }

    public function show(Plan $plan)
    {
        return $plan;
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->validated($request, true));

        return $plan->fresh();
    }

    public function destroy(Plan $plan)
    {
        if ($plan->subscriptions()->exists() || $plan->orders()->exists()) {
            return response()->json(['message' => 'Plans with subscription history cannot be deleted. Mark the plan inactive instead.'], 409);
        }
        $plan->delete();

        return response()->noContent();
    }

    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:100', 'unique:plans,name,'.($updating ? $request->route('plan')->id : 'NULL')],
            'description' => ['nullable', 'string', 'max:1000'],
            'price_cents' => [$updating ? 'sometimes' : 'required', 'integer', 'min:0'],
            'currency' => [$updating ? 'sometimes' : 'required', 'string', 'size:3'],
            'duration_days' => [$updating ? 'sometimes' : 'required', 'integer', 'between:1,3660'],
            'allowed_protocols' => [$updating ? 'sometimes' : 'required', 'array', 'min:1'],
            'allowed_protocols.*' => [Rule::in(array_keys(ProtocolRegistry::DEFINITIONS))], 'allowed_server_ids' => ['nullable', 'array'],
            'allowed_server_ids.*' => ['integer', 'exists:vpn_servers,id'],
            'connection_limit' => ['integer', 'between:1,100'], 'data_quota_bytes' => ['nullable', 'integer', 'min:0'],
            'bandwidth_mbps' => ['nullable', 'integer', 'min:1'], 'is_active' => ['boolean'],
        ]);
    }
}
