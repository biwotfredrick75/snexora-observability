<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  @page { margin: 12mm 10mm 18mm 10mm; size: A4 landscape; }

  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8.5px; color: #1f2937; }

  .page-footer {
    position: fixed; bottom: -14mm; left: 0; right: 0;
    border-top: 1px solid #d1d5db; padding-top: 3px; font-size: 7px; color: #9ca3af;
  }
  .page-footer table { width: 100%; }

  .rpt-header { border-bottom: 3px solid #0f766e; padding-bottom: 7px; margin-bottom: 7px; }
  .rpt-header table { width: 100%; }
  .rpt-title    { font-size: 17px; font-weight: bold; color: #0f766e; letter-spacing: -0.02em; }
  .rpt-subtitle { font-size: 7.5px; color: #6b7280; margin-top: 2px; }
  .co-name { font-size: 12px; font-weight: bold; color: #111827; text-align: right; }
  .co-sub  { font-size: 7.5px; color: #6b7280; text-align: right; margin-top: 1px; }

  .filter-bar {
    background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 4px;
    padding: 5px 8px; margin-bottom: 7px;
  }
  .filter-bar table { width: 100%; }
  .filter-bar td { font-size: 7.5px; padding: 1px 6px 1px 0; vertical-align: top; }
  .filter-label { color: #6b7280; font-weight: bold; white-space: nowrap; }
  .filter-val   { color: #1f2937; }

  .trunc-notice {
    background: #fffbeb; border: 1px solid #f59e0b; border-left: 4px solid #f59e0b;
    border-radius: 3px; padding: 5px 8px; margin-bottom: 7px;
    font-size: 7.5px; color: #92400e;
  }

  table.data { width: 100%; border-collapse: collapse; }
  table.data thead tr { background: #0f766e; }
  table.data thead th {
    color: #fff; padding: 5px 5px; font-size: 7.5px; font-weight: bold;
    text-transform: uppercase; letter-spacing: 0.05em; white-space: nowrap;
    border-right: 1px solid #0d9488;
  }
  table.data thead th:last-child { border-right: none; }
  table.data thead th.ta-r { text-align: right; }

  /* Grader group header row */
  table.data tbody tr.grader-hd td {
    background: #f0fdf4; color: #0f766e; font-weight: bold;
    font-size: 8px; padding: 4px 5px;
    border-top: 2px solid #0f766e; border-bottom: 1px solid #bbf7d0;
  }

  table.data tbody td {
    padding: 3px 5px; font-size: 8px;
    border-bottom: 1px solid #e5e7eb; vertical-align: middle;
  }
  table.data tbody tr.alt td { background: #f8fafc; }
  table.data tbody td.ta-r   { text-align: right; }
  table.data tbody td.mono   { font-family: DejaVu Sans Mono, monospace; font-size: 7.5px; }
  table.data tbody td.bold   { font-weight: bold; }

  table.data tfoot td {
    padding: 5px 5px; font-size: 8.5px; font-weight: bold;
    background: #f0fdf4; border-top: 2px solid #0f766e; color: #065f46;
  }
  table.data tfoot td.ta-r   { text-align: right; }
  table.data tfoot td.accent { color: #0f766e; font-size: 9px; }
</style>
</head>
<body>

<div class="page-footer">
  <table><tr>
    <td style="text-align:left">Grader Collection Report &mdash; Generated {{ $printDate }}</td>
    <td style="text-align:right">{{ $company?->name ?? '' }}</td>
  </tr></table>
</div>

{{-- Header --}}
<div class="rpt-header">
  <table><tr>
    <td style="width:60%">
      <div class="rpt-title">Grader Collection Report</div>
      <div class="rpt-subtitle">Milk collections grouped by collection clerk / station</div>
    </td>
    <td style="width:40%; vertical-align:top">
      <div class="co-name">{{ $company?->name ?? '' }}</div>
      @if($company?->email)<div class="co-sub">{{ $company->email }}</div>@endif
      @if($company?->phone)<div class="co-sub">{{ $company->phone }}</div>@endif
      <div class="co-sub" style="margin-top:3px; color:#9ca3af;">Print Date: {{ $printDate }}</div>
    </td>
  </tr></table>
</div>

{{-- Filter bar --}}
<div class="filter-bar">
  <table><tr>
    <td><span class="filter-label">Period: </span><span class="filter-val">{{ $filters['start_date'] ?? '—' }} &rarr; {{ $filters['end_date'] ?? '—' }}</span></td>
    <td><span class="filter-label">Route: </span><span class="filter-val">{{ empty($filters['route_id']) ? 'All' : $filters['route_id'] }}</span></td>
    <td><span class="filter-label">Shift: </span><span class="filter-val">{{ empty($filters['shift_id']) ? 'All' : $filters['shift_id'] }}</span></td>
    <td><span class="filter-label">Grader: </span><span class="filter-val">{{ empty($filters['grader_id']) ? 'All' : $filters['grader_id'] }}</span></td>
    <td><span class="filter-label">Supplier: </span><span class="filter-val">{{ empty($filters['farmer_id']) ? 'All' : $filters['farmer_id'] }}</span></td>
    <td><span class="filter-label">Summary: </span><span class="filter-val">{{ ($filters['summary'] ?? 'no') === 'yes' ? 'Yes' : 'No' }}</span></td>
  </tr></table>
</div>

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
      <th style="width:58px">Member No.</th>
      <th>Farmer Name</th>
      <th style="width:72px">Tel</th>
      <th style="width:62px">Date</th>
      <th style="width:80px">Route</th>
      <th style="width:60px">Shift</th>
      <th class="ta-r" style="width:56px">Qty (L)</th>
      <th class="ta-r" style="width:56px">Rate</th>
      <th class="ta-r" style="width:62px">Amount</th>
    </tr>
  </thead>
  <tbody>
    @php $currentGrader = null; $rowNum = 0; $graderQty = 0; $graderAmt = 0; @endphp
    @forelse($rows as $r)
      @php
        $grader = ($r->grader_code ?? '') . ' — ' . ($r->grader_name ?? 'Unknown');
        $isNewGrader = $grader !== $currentGrader;
      @endphp

      @if($isNewGrader)
        {{-- Subtotal row for previous grader --}}
        @if($currentGrader !== null)
        <tr>
          <td colspan="7" style="text-align:right; font-weight:bold; font-size:7.5px; color:#6b7280; padding:3px 5px;">
            Subtotal — {{ $currentGrader }}
          </td>
          <td class="ta-r" style="font-weight:bold; color:#0f766e;">{{ number_format($graderQty, 3) }}</td>
          <td></td>
          <td class="ta-r" style="font-weight:bold; color:#0f766e;">{{ number_format($graderAmt, 2) }}</td>
        </tr>
        @endif
        @php $currentGrader = $grader; $graderQty = 0; $graderAmt = 0; @endphp
        {{-- Grader group header --}}
        <tr class="grader-hd">
          <td colspan="10">&#8227; {{ $grader }}</td>
        </tr>
      @endif

      @php
        $rowNum++;
        $graderQty += (float)($r->qty ?? 0);
        $graderAmt += (float)($r->total_price ?? 0);
      @endphp
      <tr{{ $rowNum % 2 === 0 ? ' class="alt"' : '' }}>
        <td style="color:#9ca3af; text-align:center; font-size:7px;">{{ $rowNum }}</td>
        <td class="mono">{{ $r->farmer_no   ?? '—' }}</td>
        <td class="bold">{{ $r->farmer_name ?? '—' }}</td>
        <td>{{ $r->phone ?? '—' }}</td>
        <td>{{ $r->date  ?? '—' }}</td>
        <td>{{ $r->route ?? '—' }}</td>
        <td>{{ $r->shift ?? '—' }}</td>
        <td class="ta-r">{{ number_format((float)($r->qty        ?? 0), 3) }}</td>
        <td class="ta-r">{{ number_format((float)($r->unit_price ?? 0), 4) }}</td>
        <td class="ta-r bold">{{ number_format((float)($r->total_price ?? 0), 2) }}</td>
      </tr>
    @empty
      <tr><td colspan="10" style="text-align:center; padding:20px; color:#9ca3af;">No records found.</td></tr>
    @endforelse

    {{-- Last grader subtotal --}}
    @if($currentGrader !== null && count($rows) > 0)
    <tr>
      <td colspan="7" style="text-align:right; font-weight:bold; font-size:7.5px; color:#6b7280; padding:3px 5px;">
        Subtotal — {{ $currentGrader }}
      </td>
      <td class="ta-r" style="font-weight:bold; color:#0f766e;">{{ number_format($graderQty, 3) }}</td>
      <td></td>
      <td class="ta-r" style="font-weight:bold; color:#0f766e;">{{ number_format($graderAmt, 2) }}</td>
    </tr>
    @endif
  </tbody>
  <tfoot>
    <tr>
      <td colspan="7" style="text-align:right; letter-spacing:0.05em;">
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
