<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visitor;
use Illuminate\Http\Request;

class VisitorController extends Controller
{
    public function index()
    {
        $visitors = Visitor::withCount('visits')
            ->orderBy('name')
            ->get();

        return response()->json($visitors);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'ktp_number' => 'required|string',
            'name' => 'required|string',
            'company' => 'nullable|string',
            'rfid_uid' => 'nullable|string',
        ]);

        $visitor = Visitor::findOrFail($id);
        $visitor->update($validated);

        return response()->json([
            'message' => 'Visitor updated successfully',
            'visitor' => $visitor
        ]);
    }

    public function blacklist(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $visitor = Visitor::findOrFail($id);
        $visitor->update([
            'is_blacklisted' => true,
            'blacklist_reason' => $validated['reason'],
        ]);

        return response()->json([
            'message' => 'Visitor blacklisted successfully',
            'visitor' => $visitor
        ]);
    }

    public function unblacklist($id)
    {
        $visitor = Visitor::findOrFail($id);
        $visitor->update([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ]);

        return response()->json(['message' => 'Visitor removed from blacklist']);
    }
    
    public function destroy($id)
    {
        try {
            $visitor = Visitor::findOrFail($id);
            $visitor->delete();

            return response()->json(['message' => 'Visitor deleted successfully']);
        } catch (\Illuminate\Database\QueryException $e) {
            return response()->json([
                'message' => 'Cannot delete this visitor because they have existing visit history.'
            ], 422);
        }
    }
}
