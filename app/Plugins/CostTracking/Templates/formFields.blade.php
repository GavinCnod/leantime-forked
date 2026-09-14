{{--
    Injected into the ticket create modal and the full ticket edit page via the
    core `beforeEndRightColumn` template event. Sits inside the core ticket
    <form>, so both fields are posted together with the normal save action and
    picked up by the plugin's TicketCreated/TicketUpdated listeners.
--}}
<div class="form-group">
    <label class="control-label" for="costtracking_cost">{!! __('costtracking.planned_cost') !!}</label>
    <div>
        <x-global::forms.text-input
            value="{{ $costs !== null && $costs['cost'] !== null ? number_format($costs['cost'], 2, '.', '') : '' }}"
            name="costtracking_cost"
            id="costtracking_cost"
            style="width:140px;"
            step="0.01"
            inputmode="decimal" />
    </div>
</div>

<div class="form-group">
    <label class="control-label" for="costtracking_actual_cost">{!! __('costtracking.actual_cost') !!}</label>
    <div>
        <x-global::forms.text-input
            value="{{ $costs !== null && $costs['actualCost'] !== null ? number_format($costs['actualCost'], 2, '.', '') : '' }}"
            name="costtracking_actual_cost"
            id="costtracking_actual_cost"
            style="width:140px;"
            step="0.01"
            inputmode="decimal" />
    </div>
</div>
