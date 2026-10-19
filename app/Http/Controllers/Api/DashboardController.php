<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Blacklist;
use App\Models\Location;
use App\Models\VehicleCard;
use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        // 1. Visit stats — aggregated with conditional counts
        $visitStats = Visit::selectRaw('COUNT(CASE WHEN checked_out_at IS NULL THEN 1 END) AS active_visits')
            ->selectRaw('COUNT(CASE WHEN date BETWEEN ? AND ? THEN 1 END) AS month_visits', [$monthStart, $monthEnd])
            ->selectRaw('COUNT(CASE WHEN date = ? THEN 1 END) AS today_visits', [$today])
            ->selectRaw('COUNT(CASE WHEN checked_out_at IS NOT NULL AND date = ? THEN 1 END) AS today_checkouts', [$today])
            ->first();
        $activeVisits = (int) ($visitStats->active_visits ?? 0);
        $monthVisits = (int) ($visitStats->month_visits ?? 0);
        $todayVisits = (int) ($visitStats->today_visits ?? 0);
        $todayCheckouts = (int) ($visitStats->today_checkouts ?? 0);

        // 2. Visitor & Crew stats
        $visitorStats = Visitor::selectRaw('COUNT(CASE WHEN is_blacklisted = false THEN 1 END) AS total_visitors')
            ->selectRaw('COUNT(CASE WHEN is_blacklisted = true THEN 1 END) AS blacklisted_visitors')
            ->selectRaw("COUNT(CASE WHEN visitor_type = 'crew' THEN 1 END) AS total_crew")
            ->first();
        $totalVisitors = (int) ($visitorStats->total_visitors ?? 0);
        $blacklistedVisitors = (int) ($visitorStats->blacklisted_visitors ?? 0);
        $totalCrew = (int) ($visitorStats->total_crew ?? 0);

        // Active Blacklist entries from blacklists table (standard of truth)
        $activeBlacklistsTable = Blacklist::where('is_active', true)->count();

        // Active crew onboard (from vessel_arrival_crew where status = 'checked_in')
        $activeCrewOnboard = 0;
        if (Schema::hasTable('vessel_arrival_crew')) {
            $activeCrewOnboard = DB::table('vessel_arrival_crew')
                ->where('status', 'checked_in')
                ->count();
        }

        // 3. Vessel stats
        $totalVessels = Location::where('type', '!=', 'location')->count();
        $activeVesselsInYard = 0;
        $activeVesselsList = [];

        if (Schema::hasTable('vessel_arrivals')) {
            $activeVesselsInYard = VesselArrival::where(function ($q) use ($today) {
                $q->whereNull('sign_out_date')->orWhere('sign_out_date', '>=', $today);
            })->count();

            $activeVesselsList = VesselArrival::with(['vessel'])
                ->where(function ($q) use ($today) {
                    $q->whereNull('sign_out_date')->orWhere('sign_out_date', '>=', $today);
                })
                ->orderBy('sign_in_date', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($va) {
                    $crewCount = DB::table('vessel_arrival_crew')
                        ->where('vessel_arrival_id', $va->id)
                        ->where('status', 'checked_in')
                        ->count();

                    return [
                        'id' => $va->id,
                        'vessel_name' => $va->vessel_name ?: ($va->vessel?->name ?? 'Vessel'),
                        'captain' => $va->captain ?: ($va->vessel?->captain ?? '-'),
                        'imo' => $va->vessel?->code ?? '-',
                        'flag' => $va->vessel?->flag ?? '-',
                        'sign_in_date' => $va->sign_in_date?->format('Y-m-d') ?? '-',
                        'active_crew_count' => $crewCount,
                    ];
                });
        }

        // 4. Visitor-card stats
        $cardStats = VisitorCard::selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(CASE WHEN status = 'available' THEN 1 END) AS available")
            ->selectRaw("COUNT(CASE WHEN status = 'in_use' THEN 1 END) AS in_use")
            ->first();
        $totalCards = (int) ($cardStats->total ?? 0);
        $availableCards = (int) ($cardStats->available ?? 0);
        $inUseCards = (int) ($cardStats->in_use ?? 0);

        // 5. Vehicle-card stats
        $vehicleCardStats = VehicleCard::selectRaw('COUNT(*) AS total')
            ->selectRaw("COUNT(CASE WHEN status = 'available' THEN 1 END) AS available")
            ->selectRaw("COUNT(CASE WHEN status = 'in_use' THEN 1 END) AS in_use")
            ->first();
        $totalVehicleCards = (int) ($vehicleCardStats->total ?? 0);
        $availableVehicleCards = (int) ($vehicleCardStats->available ?? 0);
        $inUseVehicleCards = (int) ($vehicleCardStats->in_use ?? 0);

        // 6. Chart — visits per day last 7 days
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

        // 7. Recent open visits (enriched with full context: ticket, company, location, cards, etc.)
        $recentVisits = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard'])
            ->open()
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($visit) {
                $cards = $visit->visitorCards->pluck('card_no')->filter()->values()->all();
                $isVessel = $visit->location && $visit->location->type !== 'location';

                return [
                    'id' => $visit->id,
                    'ticket_number' => $visit->ticket_number ?: ($visit->legacy_registration_no ?: '#'.$visit->id),
                    'visitor_name' => $visit->visitor?->name ?? '-',
                    'visitor_type' => $visit->visitor?->visitor_type ?? 'visitor',
                    'company' => $visit->visitor_company ?: ($visit->visitor?->company ?? '-'),
                    'location_name' => $visit->location_name ?: ($visit->location?->name ?? '-'),
                    'is_vessel' => (bool) $isVessel,
                    'staff_name' => $visit->host_name ?: ($visit->staff?->name ?? '-'),
                    'time_in' => $visit->time_in ? Carbon::parse($visit->time_in)->format('H:i') : ($visit->check_in_at ? Carbon::parse($visit->check_in_at)->format('H:i') : '-'),
                    'purpose' => $visit->purpose ?? '-',
                    'card_no' => ! empty($cards) ? implode(', ', $cards) : '-',
                    'vehicle_no' => $visit->vehicle_no ?: ($visit->vehicleCard?->card_no ?? '-'),
                ];
            });

        // 8. Recent crew arrival logs
        $recentCrewLogs = [];
        if (Schema::hasTable('vessel_arrival_crew_logs')) {
            $recentCrewLogs = DB::table('vessel_arrival_crew_logs')
                ->join('visitors', 'vessel_arrival_crew_logs.visitor_id', '=', 'visitors.id')
                ->join('vessel_arrivals', 'vessel_arrival_crew_logs.vessel_arrival_id', '=', 'vessel_arrivals.id')
                ->select(
                    'vessel_arrival_crew_logs.id',
                    'vessel_arrival_crew_logs.action',
                    'vessel_arrival_crew_logs.source',
                    'vessel_arrival_crew_logs.logged_at',
                    'visitors.name as crew_name',
                    'visitors.occupation',
                    'vessel_arrivals.vessel_name'
                )
                ->orderBy('vessel_arrival_crew_logs.logged_at', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($log) {
                    return [
                        'id' => $log->id,
                        'crew_name' => $log->crew_name,
                        'occupation' => $log->occupation ?: 'Crew',
                        'vessel_name' => $log->vessel_name ?: 'Vessel',
                        'action' => $log->action,
                        'time' => Carbon::parse($log->logged_at)->format('d/m H:i'),
                    ];
                });
        }

        return response()->json([
            'stats' => [
                'active_visits' => $activeVisits,
                'month_visits' => $monthVisits,
                'today_visits' => $todayVisits,
                'today_checkouts' => $todayCheckouts,
                'total_visitors' => $totalVisitors,
                'blacklisted_visitors' => $blacklistedVisitors,
                'active_blacklists_count' => $activeBlacklistsTable,
                'active_crew' => $activeCrewOnboard,
                'total_crew' => $totalCrew,
                'active_vessels' => $activeVesselsInYard,
                'total_vessels' => $totalVessels,
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
            'vessels' => [
                'total' => $totalVessels,
                'active_in_yard' => $activeVesselsInYard,
                'active_list' => $activeVesselsList,
            ],
            'crew' => [
                'active_onboard' => $activeCrewOnboard,
                'total_registered' => $totalCrew,
                'recent_logs' => $recentCrewLogs,
            ],
            'chart' => $chartData,
            'recent_visits' => $recentVisits,
        ]);
    }
}
