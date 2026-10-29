<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Vessel Crew Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 8px;
            color: #111;
        }
        .header {
            text-align: center;
            font-weight: bold;
            margin-bottom: 12px;
            font-size: 12px;
            line-height: 1.4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5px;
        }
        th, td {
            border: 1px solid #333;
            padding: 3px 4px;
            text-align: left;
            word-wrap: break-word;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }
        .footer {
            margin-top: 12px;
            font-size: 7.5px;
            text-align: right;
            color: #555;
        }
    </style>
</head>
<body>

    <div class="header">
        {{ mb_strtoupper(config('vms.branding.company_name') ?: 'Visitor Management System') }}<br>
        {{ ($mode ?? 'placements') === 'history' ? 'VESSEL CREW ACTIVITY & HISTORY REPORT (LAPORAN AKTIVITAS KRU)' : 'VESSEL CREW REPORT (LAPORAN KRU KAPAL)' }}<br>
        <span style="font-size: 10px; font-weight: normal;">
            PERIODE : 
            {{ !empty($startDate) ? \Carbon\Carbon::parse($startDate)->format('d/m/Y') : 'SEMUA' }} s/d 
            {{ !empty($endDate) ? \Carbon\Carbon::parse($endDate)->format('d/m/Y') : 'SEMUA' }}
        </span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 25px;">NO</th>
                <th>Crew No</th>
                <th>Crew Name</th>
                <th>Type ID</th>
                <th>ID Number</th>
                <th>Company</th>
                <th>Occupation</th>
                <th>Vessel Name</th>
                <th>Date</th>
                <th>In</th>
                <th>Out</th>
                <th>Days</th>
                <th>Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @php $list = $records ?? $placements ?? collect(); @endphp
            @foreach($list as $index => $row)
            @php
                $crew = $row->visitor;
                $arrival = $row->vesselArrival;
                $statusLabel = match ($row->status) {
                    'checked_in' => 'Di Kapal',
                    'checked_out' => 'Check-Out',
                    'expected' => 'Belum Masuk',
                    default => ucfirst((string) $row->status),
                };

                $checkInAt = $row->checked_in_at ?? $row->check_in_at ?? null;
                $checkOutAt = $row->checked_out_at ?? $row->check_out_at ?? null;

                $start = $checkInAt ? \Carbon\Carbon::parse($checkInAt) : null;
                $end = $checkOutAt ? \Carbon\Carbon::parse($checkOutAt) : ($row->status === 'checked_in' ? now() : null);
                $days = ($start && $end) ? (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) : 0;
            @endphp
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td style="text-align: center;">{{ $crew?->visitor_no ?? '-' }}</td>
                <td><strong>{{ $crew?->name ?? '-' }}</strong></td>
                <td style="text-align: center;">{{ strtoupper($crew?->id_type ?? '-') }}</td>
                <td>{{ $crew?->id_number ?? '-' }}</td>
                <td>{{ $crew?->company ?? '-' }}</td>
                <td>{{ $crew?->occupation ?? '-' }}</td>
                <td>{{ $arrival?->vessel_name ?? $arrival?->vessel?->name ?? '-' }}</td>
                <td style="text-align: center;">
                    {{ $checkInAt ? \Carbon\Carbon::parse($checkInAt)->format('d/m/Y') : ($checkOutAt ? \Carbon\Carbon::parse($checkOutAt)->format('d/m/Y') : ($arrival?->sign_in_date ? \Carbon\Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-')) }}
                </td>
                <td style="text-align: center; font-size: 7px;">
                    {{ $checkInAt ? \Carbon\Carbon::parse($checkInAt)->format('d/m/Y H:i:s') : '-' }}
                </td>
                <td style="text-align: center; font-size: 7px;">
                    @if($checkOutAt)
                        {{ \Carbon\Carbon::parse($checkOutAt)->format('d/m/Y H:i:s') }}
                    @elseif($row->status === 'checked_in')
                        <span style="font-weight: bold; color: #166534;">ONBOARD</span>
                    @elseif($row->status === 'expected')
                        <span style="color: #6b7280;">BELUM IN</span>
                    @else
                        -
                    @endif
                </td>
                <td style="text-align: center;">{{ $days }}</td>
                <td style="text-align: center;">{{ $statusLabel }}</td>
                <td>{{ $row->notes ?? ($row->performed_by_name ? 'Petugas: '.$row->performed_by_name : '-') }}</td>
            </tr>
            @endforeach

            @if($list->isEmpty())
            <tr>
                <td colspan="14" style="text-align: center; padding: 10px;">No crew records available for this period.</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Printed by: {{ $printedBy ?? 'System' }} | Date: {{ now()->format('d/m/Y H:i:s') }}
    </div>

</body>
</html>
