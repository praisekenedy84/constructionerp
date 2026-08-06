<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 34px 42px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        h1, h2, p { margin: 0; }
        .header { border-bottom: 3px solid #1d4ed8; margin-bottom: 28px; padding-bottom: 16px; }
        .company { font-size: 22px; font-weight: bold; color: #1d4ed8; }
        .logo { float: left; margin-right: 14px; max-height: 54px; max-width: 110px; }
        .muted { color: #64748b; }
        .title { float: right; font-size: 26px; font-weight: bold; letter-spacing: 2px; }
        .clearfix::after { clear: both; content: ""; display: table; }
        .columns { margin-bottom: 24px; width: 100%; }
        .columns td { vertical-align: top; width: 50%; }
        .label { color: #64748b; font-size: 10px; text-transform: uppercase; }
        .value { font-weight: bold; margin: 3px 0 10px; }
        table.amounts { border-collapse: collapse; margin-top: 16px; width: 100%; }
        .amounts th { background: #eff6ff; border-bottom: 2px solid #bfdbfe; padding: 10px; text-align: left; }
        .amounts td { border-bottom: 1px solid #e2e8f0; padding: 10px; }
        .amount { text-align: right !important; }
        .total td { background: #f8fafc; border-top: 2px solid #1d4ed8; font-size: 14px; font-weight: bold; }
        .signatures { margin-top: 58px; width: 100%; }
        .signatures td { padding-right: 45px; vertical-align: bottom; width: 50%; }
        .signature-line { border-top: 1px solid #334155; padding-top: 6px; }
        .signature-image { height: 55px; max-width: 170px; object-fit: contain; }
        .footer { bottom: 18px; color: #94a3b8; font-size: 9px; left: 42px; position: fixed; right: 42px; text-align: center; }
    </style>
</head>
<body>
    <div class="header clearfix">
        <div class="title">INVOICE</div>
        @if($companyLogoUrl)
            <img class="logo" src="{{ $companyLogoUrl }}" alt="">
        @endif
        <div class="company">{{ $companyName }}</div>
        @if($companyTagline)
            <div class="muted">{{ $companyTagline }}</div>
        @endif
        @if($companyAddress)
            <div class="muted">{{ $companyAddress }}</div>
        @endif
        @if($companyContact)
            <div class="muted">{{ $companyContact }}</div>
        @endif
    </div>

    <table class="columns">
        <tr>
            <td>
                <div class="label">Bill To</div>
                <div class="value">{{ $invoice->customer->name }}</div>
                @if($invoice->customer->contact)<div>Phone: {{ $invoice->customer->contact }}</div>@endif
                @if($invoice->customer->tax_information)<div>TIN: {{ $invoice->customer->tax_information }}</div>@endif
                @if($invoice->customer->address)<div>Location: {{ $invoice->customer->address }}</div>@endif
            </td>
            <td>
                <div class="label">Invoice Number</div>
                <div class="value">{{ $invoice->invoice_number }}</div>
                <div class="label">Invoice Date</div>
                <div class="value">{{ $invoice->invoice_date->format('d/m/Y') }}</div>
                <div class="label">Due Date</div>
                <div class="value">{{ $invoice->due_date->format('d/m/Y') }}</div>
            </td>
        </tr>
    </table>

    <div class="label">Project</div>
    <div class="value">{{ $invoice->project->name }} ({{ $invoice->project->code }})</div>
    @if($invoice->project->location)
        <div class="muted" style="margin-bottom: 10px;">{{ $invoice->project->location }}</div>
    @endif
    <div class="label">Phase</div>
    <div class="value">{{ $invoice->phase->name }}</div>
    @if($invoice->description)
        <div class="label">Description</div>
        <div>{{ $invoice->description }}</div>
    @endif

    <table class="amounts">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amount">Amount (TZS)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $invoice->phase->name }} amount before tax</td>
                <td class="amount">{{ number_format((float) $invoice->amount_before_tax, 2) }}</td>
            </tr>
            @if((float) $invoice->tax_amount > 0)
                <tr>
                    <td>
                        {{ $invoice->tax_type ?: 'Tax' }}
                        ({{ number_format((float) $invoice->tax_rate, 2) }}%
                        {{ $invoice->tax_mode?->value === 'inclusive' ? 'inclusive' : 'exclusive' }})
                    </td>
                    <td class="amount">{{ number_format((float) $invoice->tax_amount, 2) }}</td>
                </tr>
            @endif
            @if((float) $invoice->deduction_amount > 0)
                <tr>
                    <td>{{ $invoice->deduction_type ?: 'Deduction' }} ({{ number_format((float) $invoice->deduction_rate, 2) }}%)</td>
                    <td class="amount">-{{ number_format((float) $invoice->deduction_amount, 2) }}</td>
                </tr>
            @endif
            <tr class="total">
                <td>Total Invoice Amount</td>
                <td class="amount">TZS {{ number_format((float) $invoice->total_amount, 2) }}</td>
            </tr>
        </tbody>
    </table>

    @php
        $prepared = $invoice->signatures->firstWhere('signature_type', 'prepared_by');
        $approved = $invoice->signatures->firstWhere('signature_type', 'approved_by');
    @endphp
    <table class="signatures">
        <tr>
            <td>
                @if($prepared)
                    <img class="signature-image" src="{{ storage_path('app/public/'.$prepared->signature_file) }}">
                @else
                    <div style="height: 55px"></div>
                @endif
                <div class="signature-line">Prepared By: {{ $prepared?->signer?->name ?? '' }}</div>
                <div>Date: {{ $prepared?->signed_date?->format('d/m/Y') ?? '' }}</div>
            </td>
            <td>
                @if($approved)
                    <img class="signature-image" src="{{ storage_path('app/public/'.$approved->signature_file) }}">
                @else
                    <div style="height: 55px"></div>
                @endif
                <div class="signature-line">Approved By: {{ $approved?->signer?->name ?? '' }}</div>
                <div>Date: {{ $approved?->signed_date?->format('d/m/Y') ?? '' }}</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        {{ $companyName }} · {{ $invoice->invoice_number }} · Generated {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
