<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Models\VehicleCard;

class DashboardController extends Controller
{
    public function index()
    {
        $thisMonth = now()->format('Y-m');

        // Stats
        $activeVisits = Visit::whereNull('time_out')->count();
        $monthVisits = Visit::where('date', 'like', "{$thisMonth}%")->count();
        $totalVisitors = Visitor::where('is_blacklisted', false)->count();
        $blacklistedVisitors = Visitor::where('is_blacklisted', true)->count();

        // Cards
        $totalCards = VisitorCard::count();
        $availableCards = VisitorCard::where('status', 'available')->count();
        $inUseCards = VisitorCard::where('status', 'in_use')->count();

        // Vehicle Cards
        $totalVehicleCards = VehicleCard::count();
        $availableVehicleCards = VehicleCard::where('status', 'available')->count();
        $inUseVehicleCards = VehicleCard::where('status', 'in_use')->count();

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
                    'visitor_name' => $visit->visitor->name,
                    'staff_name' => $visit->staff->name ?? '-',
                    'time_in' => \Carbon\Carbon::parse($visit->time_in)->format('H:i'),
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
