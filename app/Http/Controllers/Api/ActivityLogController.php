<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * List activity logs, newest first, with optional filters:
     *   ?causer_id=...        who did it
     *   ?action=updated       created | updated | deleted | restored | login | logout | login_failed
     *   ?subject_type=Staff   short class name, e.g. Staff, Visitor, Visit, VehicleCard, VisitorCard, User, Role
     *   ?subject_id=...       a specific record
     *   ?from=2026-08-01&to=2026-08-31
     *   ?search=budi          matches causer name/email or subject label
     *   ?per_page=25
     */
    public function index(Request $request)
    {
        $query = ActivityLog::query()->with('causer:id,name,email')->latest('created_at');

        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->input('causer_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('subject_type')) {
            $type = $request->input('subject_type');
            $fqcn = str_contains($type, '\\') ? $type : "App\\Models\\{$type}";
            $query->where('subject_type', $fqcn);
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->input('subject_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('causer_name', 'like', $term)
                    ->orWhere('causer_email', 'like', $term)
                    ->orWhere('subject_label', 'like', $term);
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100) ?: 25;

        $logs = $query->paginate($perPage)->through(function (ActivityLog $log) {
            return [
                'id' => $log->id,
                'causer_id' => $log->causer_id,
                'causer_name' => $log->causer_name,
                'causer_email' => $log->causer_email,
                'action' => $log->action,
                'subject_type' => $log->subject_short_type,
                'subject_id' => $log->subject_id,
                'subject_label' => $log->subject_label,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at,
            ];
        });

        return response()->json($logs);
    }

    /**
     * Distinct subject types currently present, for populating a filter dropdown.
     */
    public function subjectTypes()
    {
        $types = ActivityLog::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->pluck('subject_type')
            ->map(fn ($type) => class_basename($type))
            ->unique()
            ->values();

        return response()->json($types);
    }
}
