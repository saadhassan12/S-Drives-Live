<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use Illuminate\Http\Request;

class RideHistoryController extends Controller
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    /** GET /api/driver/rides/history?page=1&limit=10&status=completed|canceled */
    public function driver(Request $request)
    {
        $user = auth()->user();

        if ($user->role !== 'driver') {
            return apiResponse([], 'Unauthorized', 403, false);
        }

        return $this->history($request, 'driver_id', $user->id);
    }

    /** GET /api/ride/history?page=1&limit=10&status=completed|canceled */
    public function user(Request $request)
    {
        return $this->history($request, 'user_id', auth()->id());
    }

    private function history(Request $request, string $ownerColumn, int $ownerId)
    {
        $data = $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1',
            'status' => 'nullable|in:completed,canceled,cancelled',
        ]);

        $page = (int) ($data['page'] ?? 1);
        $limit = min((int) ($data['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT);

        $statuses = ['completed', 'canceled'];
        if (!empty($data['status'])) {
            $statuses = [$data['status'] === 'cancelled' ? 'canceled' : $data['status']];
        }

        // Only this person's rides; the query is paged in SQL (COUNT + LIMIT/OFFSET on an index).
        $paginator = Ride::query()
            ->select('rides.*')
            ->selectSub(
                'select c.reason from cancel_ride c where c.ride_id = rides.id order by c.id desc limit 1',
                'cancel_reason'
            )
            ->selectSub(
                'select c.canceled_by from cancel_ride c where c.ride_id = rides.id order by c.id desc limit 1',
                'canceled_by'
            )
            ->where("rides.$ownerColumn", $ownerId)
            ->whereIn('rides.status', $statuses)
            // Same row shape as the legacy history endpoints (the app model parses all of these).
            ->with(['driver', 'user_pe', 'vehicles', 'ratings', 'vehicleCategory:id,name'])
            ->orderByDesc('rides.updated_at')
            ->orderByDesc('rides.id')
            ->paginate($limit, ['*'], 'page', $page);

        // Push tokens are not for the app.
        $paginator->getCollection()->each(function ($ride) {
            $ride->driver?->makeHidden('device_token');
            $ride->user_pe?->makeHidden('device_token');
        });

        $total = $paginator->total();
        $totalPages = (int) ceil($total / $limit);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'Ride history retrieved successfully.',
            'data' => $paginator->items(),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasNextPage' => $page < $totalPages,
            'hasPreviousPage' => $page > 1,
        ]);
    }
}
