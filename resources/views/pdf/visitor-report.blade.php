<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Visitor Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            color: #111;
        }
        .header {
            text-align: center;
            font-weight: bold;
            margin-bottom: 15px;
            font-size: 13px;
            line-height: 1.4;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        th, td {
            border: 1px solid #333;
            padding: 4px;
            text-align: left;
            word-wrap: break-word;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            text-align: center;
        }
        .footer {
            margin-top: 15px;
            font-size: 8px;
            text-align: right;
            color: #555;
        }
    </style>
</head>
<body>

    <div class="header">
        {{ mb_strtoupper(config('vms.branding.company_name') ?: 'Visitor Management System') }}<br>
        VISITOR REPORT<br>
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
                <th>Ticket No</th>
                <th>Visitor Name</th>
                <th>Type ID</th>
                <th>ID Number</th>
                <th>Company</th>
                <th>Location</th>
                <th>Location Code</th>
                <th>Staff To Visit</th>
                <th>Purpose</th>
                <th>Cards</th>
                <th>Date</th>
                <th>In</th>
                <th>Out</th>
                <th>Days</th>
                <th>Vehicle No</th>
            </tr>
        </thead>
        <tbody>
            @foreach($visits as $index => $visit)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>{{ $visit->ticket_number ?? $visit->legacy_registration_no ?? '-' }}</td>
                <td>{{ $visit->visitor?->name ?? '-' }}</td>
                <td style="text-align: center;">{{ strtoupper($visit->visitor?->id_type ?? '-') }}</td>
                <td>{{ $visit->visitor?->id_number ?? '-' }}</td>
                <td>{{ $visit->visitor_company ?: ($visit->visitor?->company ?? '-') }}</td>
                <td>{{ $visit->location_name ?: ($visit->location?->name ?? '-') }}</td>
                <td>{{ $visit->location?->code ?? '-' }}</td>
                <td>{{ $visit->host_name ?: ($visit->staff?->name ?? '-') }}</td>
                <td>{{ $visit->purpose ?? '-' }}</td>
                <td>{{ $visit->visitorCards->pluck('card_no')->join(', ') ?: ($visit->visitorCard?->card_no ?? '-') }}</td>
                <td style="text-align: center;">{{ $visit->date ? \Carbon\Carbon::parse($visit->date)->format('d/m/Y') : '-' }}</td>
                <td style="text-align: center;">{{ $visit->time_in ? \Carbon\Carbon::parse($visit->time_in)->format('H:i') : '-' }}</td>
                <td style="text-align: center;">{{ $visit->time_out ? \Carbon\Carbon::parse($visit->time_out)->format('H:i') : '-' }}</td>
                <td style="text-align: center;">{{ $visit->days_open }}</td>
                <td>{{ $visit->vehicle_no ?? '-' }}</td>
            </tr>
            @endforeach

            @if($visits->isEmpty())
            <tr>
                <td colspan="16" style="text-align: center; padding: 10px;">No data available for this period.</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Printed by: {{ $printedBy ?? 'System' }} | Date: {{ now()->format('d/m/Y H:i:s') }}
    </div>

</body>
</html>
