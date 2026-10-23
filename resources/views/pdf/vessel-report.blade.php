<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $mode === 'list_ships_with_crew' ? 'Vessel Crew Manifest Report' : 'Vessel Report' }}</title>
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
        {{ $mode === 'list_ships_with_crew' ? 'VESSEL ARRIVAL & CREW REPORT (RINCIAN BERSAMA KRU)' : 'VESSEL ARRIVAL REPORT (RINGKASAN KAPAL)' }}<br>
        <span style="font-size: 10px; font-weight: normal;">
            PERIODE : 
            {{ !empty($startDate) ? \Carbon\Carbon::parse($startDate)->format('d/m/Y') : 'SEMUA' }} s/d 
            {{ !empty($endDate) ? \Carbon\Carbon::parse($endDate)->format('d/m/Y') : 'SEMUA' }}
        </span>
    </div>

    @if($mode === 'list_ships_with_crew')
    <table>
        <thead>
            <tr>
                <th style="width: 20px;">NO</th>
                <th>IMO / CODE</th>
                <th>VESSEL NAME</th>
                <th>SIGN IN</th>
                <th>SIGN OUT</th>
                <th>DAYS</th>
                <th>CAPTAIN</th>
                <th>CREW NO</th>
                <th>CREW NAME</th>
                <th>ID TYPE</th>
                <th>ID NUMBER</th>
                <th>GENDER</th>
                <th>OCCUPATION</th>
                <th>NATIONALITY</th>
                <th>CERTIFICATE</th>
                <th>SEAMAN BOOK</th>
            </tr>
        </thead>
        <tbody>
            @php $rowNo = 1; @endphp
            @foreach($arrivals as $arrival)
                @if($arrival->crew->isEmpty())
                <tr>
                    <td style="text-align: center;">{{ $rowNo++ }}</td>
                    <td>{{ $arrival->vessel?->code ?? '-' }}</td>
                    <td>{{ $arrival->vessel_name ?? $arrival->vessel?->name ?? '-' }}</td>
                    <td style="text-align: center;">{{ $arrival->sign_in_date ? \Carbon\Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-' }}</td>
                    <td style="text-align: center;">{{ $arrival->sign_out_date ? \Carbon\Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-' }}</td>
                    <td style="text-align: center;">{{ $arrival->total_days }}</td>
                    <td>{{ $arrival->captain ?? '-' }}</td>
                    <td style="text-align: center;">-</td>
                    <td>-</td>
                    <td style="text-align: center;">-</td>
                    <td>-</td>
                    <td style="text-align: center;">-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                    <td>-</td>
                </tr>
                @else
                    @foreach($arrival->crew as $crew)
                    <tr>
                        <td style="text-align: center;">{{ $rowNo++ }}</td>
                        <td>{{ $arrival->vessel?->code ?? '-' }}</td>
                        <td>{{ $arrival->vessel_name ?? $arrival->vessel?->name ?? '-' }}</td>
                        <td style="text-align: center;">{{ $arrival->sign_in_date ? \Carbon\Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-' }}</td>
                        <td style="text-align: center;">{{ $arrival->sign_out_date ? \Carbon\Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-' }}</td>
                        <td style="text-align: center;">{{ $arrival->total_days }}</td>
                        <td>{{ $arrival->captain ?? '-' }}</td>
                        <td style="text-align: center;">{{ $crew->visitor_no ?? '-' }}</td>
                        <td>{{ $crew->name ?? '-' }}</td>
                        <td style="text-align: center;">{{ strtoupper($crew->id_type ?? '-') }}</td>
                        <td>{{ $crew->id_number ?? '-' }}</td>
                        <td style="text-align: center;">{{ $crew->gender ?? '-' }}</td>
                        <td>{{ $crew->occupation ?? '-' }}</td>
                        <td>{{ $crew->nationality ?? '-' }}</td>
                        <td>{{ $crew->certificate ?? '-' }}</td>
                        <td>{{ $crew->seaman_book_no ?? '-' }}</td>
                    </tr>
                    @endforeach
                @endif
            @endforeach

            @if($arrivals->isEmpty())
            <tr>
                <td colspan="16" style="text-align: center; padding: 10px;">No vessel records available for this period.</td>
            </tr>
            @endif
        </tbody>
    </table>
    @else
    <table>
        <thead>
            <tr>
                <th style="width: 25px;">NO</th>
                <th>IMO / CODE</th>
                <th>VESSEL NAME</th>
                <th>SIGN IN DATE</th>
                <th>SIGN IN PORT</th>
                <th>SIGN OUT DATE</th>
                <th>SIGN OUT PORT</th>
                <th>DAYS</th>
                <th>CAPTAIN</th>
                <th>LOCAL AGENT</th>
                <th>FOREIGN AGENT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($arrivals as $index => $arrival)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>{{ $arrival->vessel?->code ?? '-' }}</td>
                <td>{{ $arrival->vessel_name ?? $arrival->vessel?->name ?? '-' }}</td>
                <td style="text-align: center;">{{ $arrival->sign_in_date ? \Carbon\Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-' }}</td>
                <td>{{ $arrival->sign_in_port ?? '-' }}</td>
                <td style="text-align: center;">{{ $arrival->sign_out_date ? \Carbon\Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-' }}</td>
                <td>{{ $arrival->sign_out_port ?? '-' }}</td>
                <td style="text-align: center;">{{ $arrival->total_days }}</td>
                <td>{{ $arrival->captain ?? '-' }}</td>
                <td>{{ $arrival->local_agent_company ?? '-' }}</td>
                <td>{{ $arrival->foreign_agent_company ?? '-' }}</td>
            </tr>
            @endforeach

            @if($arrivals->isEmpty())
            <tr>
                <td colspan="11" style="text-align: center; padding: 10px;">No vessel records available for this period.</td>
            </tr>
            @endif
        </tbody>
    </table>
    @endif

    <div class="footer">
        Printed by: {{ $printedBy ?? 'System' }} | Date: {{ now()->format('d/m/Y H:i:s') }}
    </div>

</body>
</html>
