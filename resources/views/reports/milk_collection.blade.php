<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page {
    margin: 12mm 10mm 18mm 10mm;
    size: A4 landscape;
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5px; color: #1f2937; }

  /* ── Page footer (fixed — repeats on every page) ───────────────── */
  .page-footer {
    position: fixed;
    bottom: -14mm;
    left: 0; right: 0;
    border-top: 1px solid #d1d5db;
    padding-top: 3px;
    font-size: 7px;
    color: #9ca3af;
  }
  .page-footer table { width: 100%; }
  .page-footer .pf-left  { text-align: left; }
  .page-footer .pf-right { text-align: right; }

  /* ── Report header ──────────────────────────────────────────────── */
  .rpt-header {
    border-bottom: 3px solid #1d4ed8;
    padding-bottom: 7px;
    margin-bottom: 7px;
  }
  .rpt-header table { width: 100%; }
  .rpt-title {
    font-size: 17px;
    font-weight: bold;
    color: #1d4ed8;
    letter-spacing: -0.02em;
  }
  .rpt-subtitle { font-size: 7.5px; color: #6b7280; margin-top: 2px; }
  .co-name { font-size: 12px; font-weight: bold; color: #111827; text-align: right; }
  .co-sub  { font-size: 7.5px; color: #6b7280; text-align: right; margin-top: 1px; }

  /* ── Filter summary bar ─────────────────────────────────────────── */
  .filter-bar {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    padding: 5px 8px;
    margin-bottom: 7px;
  }
  .filter-bar table { width: 100%; }
  .filter-bar td { font-size: 7.5px; padding: 1px 6px 1px 0; vertical-align: top; }
  .filter-label { color: #6b7280; font-weight: bold; white-space: nowrap; }
  .filter-val   { color: #1f2937; }

  /* ── Truncation notice ──────────────────────────────────────────── */
  .trunc-notice {
    background: #fffbeb;
    border: 1px solid #f59e0b;
    border-left: 4px solid #f59e0b;
    border-radius: 3px;
    padding: 5px 8px;
    margin-bottom: 7px;
    font-size: 7.5px;
    color: #92400e;
  }
  .trunc-notice strong { font-weight: bold; }

  /* ── Data table ─────────────────────────────────────────────────── */
  table.data { width: 100%; border-collapse: collapse; }

  table.data thead tr {
    background: #1d4ed8;
  }
  table.data thead th {
    color: #ffffff;
    padding: 5px 5px;
    font-size: 7.5px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    white-space: nowrap;
    border-right: 1px solid #2563eb;
  }
  table.data thead th:last-child { border-right: none; }
  table.data thead th.ta-r { text-align: right; }

  table.data tbody td {
    padding: 3px 5px;
    font-size: 8px;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
  }
  table.data tbody tr.alt td { background: #f8fafc; }
  table.data tbody td.ta-r   { text-align: right; }
  table.data tbody td.mono   { font-family: DejaVu Sans Mono, monospace; font-size: 7.5px; }
  table.data tbody td.bold   { font-weight: bold; }

  table.data tfoot td {
    padding: 5px 5px;
    font-size: 8.5px;
    font-weight: bold;
    background: #eff6ff;
    border-top: 2px solid #1d4ed8;
    color: #1e3a8a;
  }
  table.data tfoot td.ta-r { text-align: right; }
  table.data tfoot td.accent { color: #1d4ed8; font-size: 9px; }
</style>
</head>
<body>

{{-- Fixed page footer --}}
<div class="page-footer">
  <table><tr>
    <td class="pf-left">Milk Collection Report &mdash; Generated {{ $printDate }}</td>
    <td class="pf-right">{{ $company?->name ?? '' }}</td>
  </tr></table>
</div>

{{-- Report header --}}
<div class="rpt-header">
  <table><tr>
    <td style="width:60%">
      <div class="rpt-title">Milk Collection Report</div>
      <div class="rpt-subtitle">Farmer milk deliveries by route, shift, grader and date range</div>
    </td>
    <td style="width:40%; vertical-align:top">
      <div class="co-name">{{ $company?->name ?? '' }}</div>
      @if($company?->email)<div class="co-sub">{{ $company->email }}</div>@endif
      @if($company?->phone)<div class="co-sub">{{ $company->phone }}</div>@endif
      <div class="co-sub" style="margin-top:3px; color:#9ca3af;">Print Date: {{ $printDate }}</div>
    </td>
  </tr></table>
</div>

{{-- Filter summary --}}
<div class="filter-bar">
  <table><tr>
    <td>
      <span class="filter-label">Period: </span>
      <span class="filter-val">{{ $filters['start_date'] ?? '—' }} &rarr; {{ $filters['end_date'] ?? '—' }}</span>
    </td>
    <td>
      <span class="filter-label">Route: </span>
      <span class="filter-val">{{ empty($filters['route_id']) ? 'All' : $filters['route_id'] }}</span>
    </td>
    <td>
      <span class="filter-label">Shift: </span>
      <span class="filter-val">{{ empty($filters['shift_id']) ? 'All' : $filters['shift_id'] }}</span>
    </td>
    <td>
      <span class="filter-label">Store / Grader: </span>
      <span class="filter-val">{{ empty($filters['grader_id']) ? 'All' : $filters['grader_id'] }}</span>
    </td>
    <td>
      <span class="filter-label">Pricing: </span>
      <span class="filter-val">{{ empty($filters['pricing_type']) ? 'All' : ucfirst($filters['pricing_type']) }}</span>
    </td>
    <td>
      <span class="filter-label">Summary: </span>
      <span class="filter-val">{{ ($filters['summary'] ?? 'no') === 'yes' ? 'Yes' : 'No' }}</span>
    </td>
  </tr></table>
</div>

{{-- Truncation notice --}}
@if($truncated)
<div class="trunc-notice">
  <strong>&#9888; Partial Export &mdash;</strong>
  Showing first <strong>{{ number_format($shownCount) }}</strong> of <strong>{{ number_format($totalCount) }}</strong> records.
  Totals reflect ALL {{ number_format($totalCount) }} records. Use Excel to download the full dataset.
</div>
@endif

{{-- Data table --}}
<table class="data">
  <thead>
    <tr>
      <th style="width:22px">#</th>
      <th style="width:58px">No.</th>
      <th>Farmer Name</th>
      <th style="width:72px">Tel</th>
      <th style="width:62px">Date</th>
      <th style="width:72px">Route</th>
      <th style="width:100px">Store / Grader</th>
      <th style="width:60px">Shift</th>
      <th style="width:34px">Time</th>
      <th style="width:38px">User</th>
      <th class="ta-r" style="width:52px">Qty (L)</th>
      <th class="ta-r" style="width:56px">Unit Price</th>
      <th class="ta-r" style="width:60px">Amount</th>
    </tr>
  </thead>
  <tbody>
    @forelse($rows as $i => $r)
    <tr{{ $i % 2 === 1 ? ' class="alt"' : '' }}>
      <td style="color:#9ca3af; text-align:center; font-size:7px;">{{ $i + 1 }}</td>
      <td class="mono">{{ $r->farmer_no ?? '—' }}</td>
      <td class="bold">{{ $r->farmer_name ?? '—' }}</td>
      <td>{{ $r->phone ?? '—' }}</td>
      <td>{{ $r->date  ?? '—' }}</td>
      <td>{{ $r->route ?? '—' }}</td>
      <td>{{ $r->store ?? '—' }}</td>
      <td>{{ $r->shift ?? '—' }}</td>
      <td style="text-align:center;">{{ $r->time ? \Carbon\Carbon::parse($r->time)->format('H:i') : '—' }}</td>
      <td>{{ $r->user  ?? '—' }}</td>
      <td class="ta-r">{{ number_format((float)($r->qty        ?? 0), 3) }}</td>
      <td class="ta-r">{{ number_format((float)($r->unit_price ?? 0), 4) }}</td>
      <td class="ta-r bold">{{ number_format((float)($r->total_price ?? 0), 2) }}</td>
    </tr>
    @empty
    <tr><td colspan="13" style="text-align:center; padding:20px; color:#9ca3af;">No records found.</td></tr>
    @endforelse
  </tbody>
  <tfoot>
    <tr>
      <td colspan="10" style="text-align:right; letter-spacing:0.05em;">
        TOTAL{{ $truncated ? ' &mdash; all ' . number_format($totalCount) . ' records' : '' }}
      </td>
      <td class="ta-r accent">{{ number_format($totalQty, 3) }} L</td>
      <td></td>
      <td class="ta-r accent">{{ number_format($totalAmount, 2) }}</td>
    </tr>
  </tfoot>
</table>

</body>
</html>
