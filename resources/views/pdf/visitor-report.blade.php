<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Visitor Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
        }
        .header {
            text-align: center;
            font-weight: bold;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        th, td {
            border: 1px solid #000;
            padding: 5px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
    </style>
</head>
<body>

    <div class="header">
        PT CEMARA INTAN SHIPYARD<br>
        VISITOR REPORT<br>
        PERIODE : 
        {{ $startDate ? \Carbon\Carbon::parse($startDate)->format('d/m/Y') : '-' }} s/d 
        {{ $endDate ? \Carbon\Carbon::parse($endDate)->format('d/m/Y') : '-' }}
    </div>

    <table>
        <thead>
            <tr>
                <th>NO</th>
                <th>Visitor Name</th>
                <th>KTP / ID Number</th>
                <th>Company</th>
                <th>Purpose Of Visit</th>
                <th>Staff To Be Seen</th>
                <th>Card No</th>
                <th>Date</th>
                <th>Time In</th>
                <th>Time Out</th>
                <th>VEP</th>
            </tr>
        </thead>
        <tbody>
            @foreach($visits as $index => $visit)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $visit->visitor->name }}</td>
                <td>{{ $visit->visitor->ktp_number }}</td>
                <td>{{ $visit->visitor->company ?? '-' }}</td>
                <td>{{ $visit->purpose }}</td>
                <td>{{ $visit->staff?->name ?? 'Unknown' }}</td>
                <td>{{ $visit->visitorCard?->card_no ?? '-' }}</td>
                <td>{{ \Carbon\Carbon::parse($visit->date)->format('d/m/Y') }}</td>
                <td>{{ $visit->time_in }}</td>
                <td>{{ $visit->time_out ?? '-' }}</td>
                <td>{{ $visit->vehicle_no ?? '-' }}</td>
            </tr>
            @endforeach

            @if($visits->isEmpty())
            <tr>
                <td colspan="11" style="text-align: center;">No data available for this period.</td>
            </tr>
            @endif
        </tbody>
    </table>

</body>
</html>
