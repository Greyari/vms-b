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
     *   ?search=budi          matches causer name/email/username or subject label
     *   ?per_page=25
     */
    public function index(Request $request)
    {
        $query = ActivityLog::query()->with('causer:id,name,email,username')->latest('created_at');

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
            // Postgres LIKE is case-sensitive, and usernames are stored upper
            // case: compare lower-cased, with the user's wildcards escaped.
            $term = '%'.strtolower(addcslashes((string) $request->input('search'), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(causer_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(causer_email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(causer_username) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(subject_label) LIKE ?', [$term]);
            });
        }

        $perPage = max(1, min((int) $request->input('per_page', 100), 1000));

        $logs = $query->paginate($perPage)->through(function (ActivityLog $log) {
            return [
                'id' => $log->id,
                'causer_id' => $log->causer_id,
                'causer_name' => $log->causer_name,
                'causer_email' => $log->causer_email,
                'causer_username' => $log->causer_username,
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
