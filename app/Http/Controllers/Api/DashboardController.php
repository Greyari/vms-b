<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        // Visit stats — one query, aggregated with conditional counts
        // (COUNT(*) FILTER works on PostgreSQL and sqlite >= 3.30).
        $visitStats = Visit::selectRaw('COUNT(*) FILTER (WHERE time_out IS NULL) AS active_visits')
            ->selectRaw('COUNT(*) FILTER (WHERE date BETWEEN ? AND ?) AS month_visits', [$monthStart, $monthEnd])
            ->first();
        $activeVisits = (int) ($visitStats->active_visits ?? 0);
        $monthVisits = (int) ($visitStats->month_visits ?? 0);

        // Visitor stats — one query
        $visitorStats = Visitor::selectRaw('COUNT(*) FILTER (WHERE is_blacklisted = false) AS total_visitors')
            ->selectRaw('COUNT(*) FILTER (WHERE is_blacklisted = true) AS blacklisted_visitors')
            ->first();
        $totalVisitors = (int) ($visitorStats->total_visitors ?? 0);
        $blacklistedVisitors = (int) ($visitorStats->blacklisted_visitors ?? 0);

        // Visitor-card stats — one query
        $cardStats = VisitorCard::selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'available') AS available")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'in_use') AS in_use")
            ->first();
        $totalCards = (int) ($cardStats->total ?? 0);
        $availableCards = (int) ($cardStats->available ?? 0);
        $inUseCards = (int) ($cardStats->in_use ?? 0);

        // Vehicle-card stats — one query
        $vehicleCardStats = VehicleCard::selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'available') AS available")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'in_use') AS in_use")
            ->first();
        $totalVehicleCards = (int) ($vehicleCardStats->total ?? 0);
        $availableVehicleCards = (int) ($vehicleCardStats->available ?? 0);
        $inUseVehicleCards = (int) ($vehicleCardStats->in_use ?? 0);

        // Chart — visits per day last 7 days
        $visitsPerDay = Visit::selectRaw('date, COUNT(*) as total')
            ->where('date', '>=', now()->subDays(6)->toDateString())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $chartData[] = [
                'date' => now()->subDays($i)->format('d/m'),
                'total' => $visitsPerDay[$date]->total ?? 0,
            ];
        }

        // Recent visits
        $recentVisits = Visit::with(['visitor', 'staff'])
            ->whereNull('time_out')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($visit) {
                return [
                    'id' => $visit->id,
                    'ticket_number' => $visit->ticket_number,
                    'visitor_name' => $visit->visitor?->name ?? '-',
                    'staff_name' => $visit->staff?->name ?? '-',
                    'time_in' => Carbon::parse($visit->time_in)->format('H:i'),
                    'purpose' => $visit->purpose,
                ];
            });

        return response()->json([
            'stats' => [
                'active_visits' => $activeVisits,
                'month_visits' => $monthVisits,
                'total_visitors' => $totalVisitors,
                'blacklisted_visitors' => $blacklistedVisitors,
            ],
            'cards' => [
                'total' => $totalCards,
                'available' => $availableCards,
                'in_use' => $inUseCards,
            ],
            'vehicle_cards' => [
                'total' => $totalVehicleCards,
                'available' => $availableVehicleCards,
                'in_use' => $inUseVehicleCards,
            ],
            'chart' => $chartData,
            'recent_visits' => $recentVisits,
        ]);
    }
}
