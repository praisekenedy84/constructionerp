<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Income Statement</title>
    <style>
        @include('partials.pdf-fonts')
        body { font-family: Poppins, DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        .meta { margin-bottom: 16px; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; }
        th { text-align: left; }
        .amount { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #111; }
        .header td { font-weight: bold; padding-top: 14px; border-bottom: none; }
    </style>
</head>
<body>
    <h1>Income Statement</h1>
    <div class="meta">
        @if (!empty($statement['memo_no']))
            <div>Memo No.: {{ $statement['memo_no'] }}</div>
        @endif
        <div>
            Period:
            {{ $statement['period']['from'] ?? '…' }}
            to
            {{ $statement['period']['to'] ?? '…' }}
        </div>
        <div>Generated: {{ now()->toDateTimeString() }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Line</th>
                <th class="amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($statement['lines'] as $line)
                @php
                    $isHeader = str_starts_with($line['key'], 'header_');
                    $rowClass = $line['is_total'] ? 'total' : ($isHeader ? 'header' : '');
                @endphp
                <tr class="{{ $rowClass }}">
                    <td>
                        @unless ($isHeader || $line['is_total'])
                            &nbsp;&nbsp;&nbsp;&nbsp;
                        @endunless
                        {{ $line['label'] }}
                    </td>
                    <td class="amount">
                        @unless ($isHeader)
                            {{ number_format((float) $line['amount'], 2) }}
                        @endunless
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
