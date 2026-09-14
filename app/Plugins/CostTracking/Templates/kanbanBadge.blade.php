{{-- Compact cost badge on kanban cards (ticketCard.meta template event) --}}
<div class="tw-flex tw-gap-1 tw-mt-2" style="font-size:11px; line-height:1;">
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
