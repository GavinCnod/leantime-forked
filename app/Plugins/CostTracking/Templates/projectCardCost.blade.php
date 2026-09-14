{{-- Total cost line on project hub cards (projectCard.afterProgress event) --}}
<div class="tw-flex tw-justify-between tw-text-xs tw-mt-1" style="font-size:12px;">
    <span><i class="fa fa-coins"></i> {!! __('costtracking.planned_cost_short') !!}</span>
    <strong>{{ number_format((float) $cost, 2) }}</strong>
</div>
<div class="tw-flex tw-justify-between tw-text-xs" style="font-size:12px;">
    <span><i class="fa fa-receipt"></i> {!! __('costtracking.actual_cost_short') !!}</span>
    <strong class="{{ (float) $actualCost > (float) $cost ? 'ct-over' : '' }}">
        {{ number_format((float) $actualCost, 2) }}
    </strong>
</div>
