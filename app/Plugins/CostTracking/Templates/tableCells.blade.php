{{-- Two trailing cells on the full ticket table (allTicketsTable.beforeRowEnd on showAll) --}}
<td class="ct-col-cost" data-order="{{ $cost ?? '' }}">
    @if ($cost !== null)
        {{ number_format($cost, 2) }}
    @endif
</td>
<td class="ct-col-cost {{ $actualCost !== null && $cost !== null && $actualCost > $cost ? 'ct-over' : '' }}"
    data-order="{{ $actualCost ?? '' }}">
    @if ($actualCost !== null)
        {{ number_format($actualCost, 2) }}
    @endif
</td>
