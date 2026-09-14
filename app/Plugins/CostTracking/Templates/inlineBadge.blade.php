{{-- Inline cost badges inside the title cell of the simple list (showList) --}}
<div class="tw-flex tw-gap-1 tw-mt-1 ct-inline-badges" style="font-size:11px;">
    @if ($cost !== null)
        <span class="ct-badge" title="{!! __('costtracking.planned_cost') !!}">
            <i class="fa fa-coins"></i> {{ number_format($cost, 2) }}
        </span>
    @endif
    @if ($actualCost !== null)
        <span class="ct-badge {{ $cost !== null && $actualCost > $cost ? 'ct-badge-over' : '' }}"
              title="{!! __('costtracking.actual_cost') !!}">
            <i class="fa fa-receipt"></i> {{ number_format($actualCost, 2) }}
        </span>
    @endif
</div>
