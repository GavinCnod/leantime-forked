{{-- Per-group totals bar, rendered just below the table (allTicketsTable.afterClose) --}}
<div class="ct-table-totals">
    <span class="ct-totals-label">{!! __('costtracking.total') !!}:</span>
    <span class="ct-badge">
        <i class="fa fa-coins"></i> {!! __('costtracking.planned_cost_short') !!}
        {{ number_format($planned, 2) }}
    </span>
    <span class="ct-badge">
        <i class="fa fa-receipt"></i> {!! __('costtracking.actual_cost_short') !!}
        {{ number_format($actual, 2) }}
    </span>
</div>
