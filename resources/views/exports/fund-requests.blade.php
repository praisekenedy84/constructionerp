<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Fund Requests Report</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1e293b;
            margin: 24px;
        }
        .header {
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header h1 {
            margin: 0;
            font-size: 18px;
            color: #1e3a8a;
        }
        .header p {
            margin: 4px 0 0;
            color: #64748b;
        }
        .summary {
            width: 100%;
            margin-bottom: 16px;
            border-collapse: collapse;
        }
        .summary td {
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
        }
        .summary .label {
            font-weight: bold;
            color: #475569;
            width: 18%;
        }
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        table.data th {
            background: #1e3a8a;
            color: #fff;
            padding: 8px 6px;
            text-align: left;
            font-size: 9px;
        }
        table.data td {
            border: 1px solid #cbd5e1;
            padding: 6px;
            vertical-align: top;
        }
        table.data tr:nth-child(even) td {
            background: #f8fafc;
        }
        .status {
            font-weight: bold;
            text-transform: capitalize;
        }
        .amount {
            text-align: right;
            white-space: nowrap;
        }
        .footer {
            margin-top: 16px;
            font-size: 9px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $companyName }} — Fund Requests Report</h1>
        <p>Generated {{ $generatedAt }} · Filter: {{ ucfirst($statusFilter) }}</p>
    </div>

    <table class="summary">
        <tr>
            <td class="label">Total Requests</td>
            <td>{{ $summary['total'] }}</td>
            <td class="label">Pending</td>
            <td>{{ $summary['pending'] }}</td>
            <td class="label">Approved</td>
            <td>{{ $summary['approved'] }}</td>
        </tr>
        <tr>
            <td class="label">Received</td>
            <td>{{ $summary['received'] }}</td>
            <td class="label">Rejected</td>
            <td>{{ $summary['rejected'] }}</td>
            <td class="label">Total Requested (TZS)</td>
            <td>{{ $summary['total_requested'] }}</td>
        </tr>
        <tr>
            <td class="label">Total Received (TZS)</td>
            <td colspan="5">{{ $summary['total_received'] }}</td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                <th>ID</th>
                <th>Project</th>
                <th>Requester</th>
                <th>Status</th>
                <th>Requested</th>
                <th>Received</th>
                <th>Utilized</th>
                <th>Balance</th>
                <th>Method</th>
                <th>Reference</th>
                <th>Requested At</th>
                <th>Decided At</th>
                <th>Approver</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($allocations as $allocation)
                <tr>
                    <td>#{{ $allocation->id }}</td>
                    <td>
                        <strong>{{ $allocation->project?->code }}</strong><br>
                        {{ $allocation->project?->name }}
                    </td>
                    <td>{{ $allocation->requester?->name ?? '—' }}</td>
                    <td class="status">{{ $allocation->status->value }}</td>
                    <td class="amount">{{ number_format((float) $allocation->requested_amount, 2) }}</td>
                    <td class="amount">{{ number_format((float) $allocation->received_amount, 2) }}</td>
                    <td class="amount">{{ number_format((float) $allocation->utilized_amount, 2) }}</td>
                    <td class="amount">{{ number_format((float) $allocation->balance, 2) }}</td>
                    <td>{{ $allocation->method ?? '—' }}</td>
                    <td>{{ $allocation->reference_no ?? '—' }}</td>
                    <td>{{ $allocation->requested_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ $allocation->decided_at?->format('d M Y H:i') ?? '—' }}</td>
                    <td>{{ $allocation->approver?->name ?? '—' }}</td>
                    <td>{{ $allocation->rejection_reason ?? '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="14" style="text-align: center; padding: 20px;">No fund requests found.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Confidential — {{ $companyName }} · Fund allocation register for audit and record keeping.
    </div>
</body>
</html>
