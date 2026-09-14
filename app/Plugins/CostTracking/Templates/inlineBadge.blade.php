{{--
    Inline cost badges appended INSIDE the title <td> of the simple list
    (showList, allTicketsTable.afterTitleCell) so they stay attached to the
    task name. Rendered inline (not a block <div>) so it sits on the same
    line as the headline.
--}}
<span class="ct-inline-badges">
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
</span>
