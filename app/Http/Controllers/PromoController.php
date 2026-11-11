<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PromoController extends Controller
{
    /**
     * GET: active promo codes a passenger can use. Optional ?vehicle_category_id= keeps only the
     * codes valid for that vehicle (codes without a vehicle restriction always stay).
     */
    public function index(Request $request)
    {
        $request->validate(['vehicle_category_id' => 'nullable|integer']);

        $promos = DB::table('promo_codes')
            ->where('is_active', 1)
            ->when($request->filled('vehicle_category_id'), function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->whereNull('vehicle_type_id')
                        ->orWhere('vehicle_type_id', (int) $request->input('vehicle_category_id'));
                });
            })
            ->orderByDesc('id')
            ->get();

        $usage = DB::table('rides')
            ->select('promo_code', DB::raw('COUNT(*) as used'))
            ->whereIn('promo_code', $promos->pluck('code'))
            ->whereNotIn('status', ['canceled', 'cancelled'])
            ->groupBy('promo_code')
            ->pluck('used', 'promo_code');

        $data = $promos
            ->filter(fn ($p) => $p->usage_limit === null || (int) ($usage[$p->code] ?? 0) < (int) $p->usage_limit)
            ->map(fn ($p) => [
                'promo_code' => $p->code,
                'type' => $p->type,
                'value' => (float) $p->value,
                'max_discount' => $p->max_discount !== null ? (float) $p->max_discount : null,
                'min_fare' => $p->min_fare !== null ? (float) $p->min_fare : null,
                'vehicle_category_id' => $p->vehicle_type_id,
            ])
            ->values();

        return apiResponse($data, 'Promo codes retrieved successfully.');
    }

    /**
     * Passenger checks a promo code before using it. Optional `fare` and `vehicle_category_id`
     * make min_fare / vehicle_type_id apply and return the discounted fare.
     */
    public function validatePromo(Request $request)
    {
        $request->validate([
            'promo_code' => 'required|string|max:50',
            'fare' => 'nullable|numeric|min:0',
            'vehicle_category_id' => 'nullable|integer',
        ]);

        $promo = DB::table('promo_codes')
            ->where('code', trim($request->input('promo_code')))
            ->where('is_active', 1)
            ->first();

        if (!$promo) {
            return apiResponse(null, 'Invalid or inactive promo code.', 400, false);
        }

        if ($promo->usage_limit !== null) {
            $used = DB::table('rides')
                ->where('promo_code', $promo->code)
                ->whereNotIn('status', ['canceled', 'cancelled'])
                ->count();

            if ($used >= (int) $promo->usage_limit) {
                return apiResponse(null, 'This promo code has reached its usage limit.', 400, false);
            }
        }

        if ($promo->vehicle_type_id !== null && $request->filled('vehicle_category_id')
            && (int) $promo->vehicle_type_id !== (int) $request->input('vehicle_category_id')) {
            return apiResponse(null, 'This promo code is not valid for the selected vehicle.', 400, false);
        }

        $data = [
            'promo_code' => $promo->code,
            'type' => $promo->type,
            'value' => (float) $promo->value,
            'max_discount' => $promo->max_discount !== null ? (float) $promo->max_discount : null,
            'min_fare' => $promo->min_fare !== null ? (float) $promo->min_fare : null,
            'vehicle_category_id' => $promo->vehicle_type_id,
        ];

        if ($request->filled('fare')) {
            $fare = (float) $request->input('fare');

            if ($promo->min_fare !== null && $fare < (float) $promo->min_fare) {
                return apiResponse(null, 'Minimum fare for this promo code is ' . (float) $promo->min_fare . '.', 400, false);
            }

            $discount = $promo->type === 'fixed'
                ? (float) $promo->value
                : $fare * (float) $promo->value / 100;

            if ($promo->type === 'percent' && !empty($promo->max_discount) && $discount > (float) $promo->max_discount) {
                $discount = (float) $promo->max_discount;
            }

            $discount = min($discount, $fare);
            $data['fare'] = round($fare);
            $data['discount'] = round($discount);
            $data['final_fare'] = round($fare - $discount);
        }

        return apiResponse($data, 'Promo code is valid.');
    }
}
