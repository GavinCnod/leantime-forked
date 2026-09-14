@php
    $planned = (float) $rollup['cost'];
    $actual = (float) $rollup['actualCost'];
    $ticketCount = (int) $rollup['ticketCount'];

    $usage = $budget > 0 ? min($actual / $budget * 100, 999) : 0.0;
    $remaining = $budget - $actual;
    $overBudget = $budget > 0 && $actual > $budget;

    $toplevelUrl = BASE_URL.'/projects/showProject/'.$project['id'].'?costScope=toplevel#costtracking';
    $subtasksUrl = BASE_URL.'/projects/showProject/'.$project['id'].'?costScope=subtasks#costtracking';
@endphp

<div id="costtracking">
    <style>
        .ct-stat-tiles { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0; }
        .ct-stat-tile { flex: 1 1 160px; border: 1px solid var(--grey, #ddd); border-radius: 8px; padding: 12px 16px; background: var(--secondary-background, #fafafa); }
        .ct-stat-tile .ct-label { font-size: 12px; color: var(--secondary-font-color, #777); margin-bottom: 4px; }
        .ct-stat-tile .ct-value { font-size: 20px; font-weight: 600; }
        .ct-value-over { color: var(--danger, #d9534f); }
        .ct-scope-links { margin: 8px 0 4px; font-size: 13px; }
        .ct-scope-links a { margin-right: 12px; }
        .ct-scope-links .ct-active { font-weight: 700; }
        .ct-progress-track { height: 10px; border-radius: 6px; background: var(--grey, #ddd); overflow: hidden; margin-top: 6px; }
        .ct-progress-bar { height: 100%; background: var(--accent2, #1b9aaa); }
        .ct-progress-bar.ct-over { background: var(--danger, #d9534f); }
    </style>

    <div class="row">
        <div class="col-md-12">
            <h4 class="widgettitle title-light">
                <span class="fa fa-coins"></span>
                {!! __('costtracking.project_cost_summary') !!}
            </h4>

            <div class="ct-scope-links">
                <a href="{{ $toplevelUrl }}" class="{{ $scope === 'toplevel' ? 'ct-active' : '' }}">
                    {!! __('costtracking.scope_toplevel') !!}
                </a>
                <a href="{{ $subtasksUrl }}" class="{{ $scope === 'subtasks' ? 'ct-active' : '' }}">
                    {!! __('costtracking.scope_subtasks') !!}
                </a>
            </div>

            <div class="ct-stat-tiles">
                <div class="ct-stat-tile">
                    <div class="ct-label">{!! __('costtracking.planned_cost') !!}</div>
                    <div class="ct-value">{{ number_format($planned, 2) }}</div>
                </div>
                <div class="ct-stat-tile">
                    <div class="ct-label">{!! __('costtracking.actual_cost') !!}</div>
                    <div class="ct-value">{{ number_format($actual, 2) }}</div>
                </div>
                <div class="ct-stat-tile">
                    <div class="ct-label">{!! __('costtracking.budget') !!}</div>
                    <div class="ct-value">{{ number_format($budget, 2) }}</div>
                </div>
                <div class="ct-stat-tile">
                    <div class="ct-label">{!! $overBudget ? __('costtracking.over_budget') : __('costtracking.remaining') !!}</div>
                    <div class="ct-value {{ $overBudget ? 'ct-value-over' : '' }}">
                        {{ number_format(abs($remaining), 2) }}
                    </div>
                </div>
                <div class="ct-stat-tile">
                    <div class="ct-label">{!! __('costtracking.ticket_count') !!}</div>
                    <div class="ct-value">{{ $ticketCount }}</div>
                </div>
            </div>

            @if ($budget > 0)
                <div>
                    <strong>{!! __('costtracking.usage') !!}:</strong>
                    {{ number_format(min($usage, 100), 1) }}%
                    <div class="ct-progress-track">
                        <div class="ct-progress-bar {{ $overBudget ? 'ct-over' : '' }}"
                             style="width: {{ min($usage, 100) }}%;"></div>
                    </div>
                </div>
            @else
                <p class="text-muted">{!! __('costtracking.no_budget_hint') !!}</p>
            @endif
        </div>
    </div>
</div>
