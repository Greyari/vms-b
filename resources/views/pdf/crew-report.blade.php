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
        VESSEL CREW REPORT (LAPORAN KRU KAPAL)<br>
        <span style="font-size: 10px; font-weight: normal;">
            PERIODE : 
            {{ !empty($startDate) ? \Carbon\Carbon::parse($startDate)->format('d/m/Y') : 'SEMUA' }} s/d 
            {{ !empty($endDate) ? \Carbon\Carbon::parse($endDate)->format('d/m/Y') : 'SEMUA' }}
        </span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 20px;">NO</th>
                <th>CREW NO</th>
                <th>CREW NAME</th>
                <th>ID TYPE</th>
                <th>ID NUMBER</th>
                <th>GENDER</th>
                <th>NATIONALITY</th>
                <th>COMPANY</th>
                <th>OCCUPATION</th>
                <th>SEAMAN BOOK</th>
                <th>CERTIFICATE</th>
                <th>VESSEL NAME</th>
                <th>IMO / CODE</th>
                <th>STATUS</th>
                <th>CHECK-IN</th>
                <th>CHECK-OUT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($placements as $index => $placement)
            @php
                $crew = $placement->visitor;
                $arrival = $placement->vesselArrival;
                $statusLabel = match ($placement->status) {
                    'checked_in' => 'Di Kapal',
                    'checked_out' => 'Sudah Turun',
                    'expected' => 'Belum Check-In',
                    default => ucfirst((string) $placement->status),
                };
            @endphp
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td style="text-align: center;">{{ $crew?->visitor_no ?? '-' }}</td>
                <td>{{ $crew?->name ?? '-' }}</td>
                <td style="text-align: center;">{{ strtoupper($crew?->id_type ?? '-') }}</td>
                <td>{{ $crew?->id_number ?? '-' }}</td>
                <td style="text-align: center;">{{ $crew?->gender ?? '-' }}</td>
                <td>{{ $crew?->nationality ?? '-' }}</td>
                <td>{{ $crew?->company ?? '-' }}</td>
                <td>{{ $crew?->occupation ?? '-' }}</td>
                <td>{{ $crew?->seaman_book_no ?? '-' }}</td>
                <td>{{ $crew?->certificate_no ?: ($crew?->certificate ?? '-') }}</td>
                <td>{{ $arrival?->vessel_name ?? $arrival?->vessel?->name ?? '-' }}</td>
                <td>{{ $arrival?->vessel?->code ?? '-' }}</td>
                <td style="text-align: center;">{{ $statusLabel }}</td>
                <td style="text-align: center;">{{ $placement->checked_in_at ? \Carbon\Carbon::parse($placement->checked_in_at)->format('d/m/Y H:i') : '-' }}</td>
                <td style="text-align: center;">{{ $placement->checked_out_at ? \Carbon\Carbon::parse($placement->checked_out_at)->format('d/m/Y H:i') : '-' }}</td>
            </tr>
            @endforeach

            @if($placements->isEmpty())
            <tr>
                <td colspan="16" style="text-align: center; padding: 10px;">No crew placement records available for this period.</td>
            </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Printed by: {{ $printedBy ?? 'System' }} | Date: {{ now()->format('d/m/Y H:i:s') }}
    </div>

</body>
</html>
