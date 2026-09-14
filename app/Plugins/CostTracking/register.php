<?php

/**
 * CostTracking plugin bootstrap.
 *
 * Registered automatically when the plugin is enabled (included by the
 * LoadPlugins middleware). Wires plugin templates and persistence into the
 * core ticket/project flows exclusively through events and filters — no
 * core class overrides.
 */

use Leantime\Core\Events\EventDispatcher;
use Leantime\Domain\Plugins\Services\Registration;
use Leantime\Domain\Projects\Services\Projects as ProjectService;
use Leantime\Domain\Tickets\Events\TicketCreated;
use Leantime\Domain\Tickets\Events\TicketDeleted;
use Leantime\Domain\Tickets\Events\TicketListFilter;
use Leantime\Domain\Tickets\Events\TicketUpdated;
use Leantime\Plugins\CostTracking\Repositories\Costs;

/**
 * Echoes a plugin blade partial.
 */
if (! function_exists('costtracking_render')) {
    function costtracking_render(string $view, array $data = []): void
    {
        try {
            echo app('view')->make('costtracking::'.$view, $data)->render();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[CostTracking] render failed for '.$view.': '.$e->getMessage());
        }
    }
}

/**
 * Returns the payload context (['ticket' => ...] / ['project' => ...])
 * regardless of whether the listener got the raw context or a wrapped one.
 */
if (! function_exists('costtracking_context')) {
    function costtracking_context(mixed $payload, string $key): mixed
    {
        if (is_array($payload)) {
            return $payload[$key]
                ?? $payload['leantime'][$key]
                ?? $payload['laravel'][$key]
                ?? null;
        }

        return null;
    }
}

if (! function_exists('costtracking_amount')) {
    function costtracking_amount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        return is_numeric($value) ? (float) $value : null;
    }
}

/**
 * Lowercased current event name (incl. template hook context), e.g.
 * "leantime.domain.tickets.templates.showall.allTicketstable.beforeRowEnd".
 * Template events carry it in the wrapped "leantime" params.
 */
if (! function_exists('costtracking_event')) {
    function costtracking_event(mixed $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        return strtolower((string) ($payload['leantime']['currentEvent']
            ?? $payload['laravel']['currentEvent']
            ?? ''));
    }
}

/**
 * True when at least one row carries the plugin's enriched cost keys.
 * Milestone tables never pass through TicketListFilter and therefore fail
 * this guard, which keeps their column layout untouched.
 */
if (! function_exists('costtracking_enriched')) {
    function costtracking_enriched(mixed $tickets): bool
    {
        if (! is_array($tickets)) {
            return false;
        }

        foreach ($tickets as $row) {
            if (is_array($row) && array_key_exists('costtracking_cost', $row)) {
                return true;
            }
        }

        return false;
    }
}

/**
 * @return array{cost: ?float, actualCost: ?float}
 */
if (! function_exists('costtracking_row_costs')) {
    function costtracking_row_costs(mixed $row): array
    {
        if (is_array($row)) {
            return [
                'cost' => isset($row['costtracking_cost']) ? (float) $row['costtracking_cost'] : null,
                'actualCost' => isset($row['costtracking_actual_cost']) ? (float) $row['costtracking_actual_cost'] : null,
            ];
        }

        if (is_object($row)) {
            return [
                'cost' => isset($row->costtracking_cost) ? (float) $row->costtracking_cost : null,
                'actualCost' => isset($row->costtracking_actual_cost) ? (float) $row->costtracking_actual_cost : null,
            ];
        }

        return ['cost' => null, 'actualCost' => null];
    }
}

(new Registration('CostTracking'))->registerLanguageFiles(['en-US', 'zh-CN']);

/*
| Self-contained styles for list columns and badges. Echoed as one <style>
| block in <head> via the core afterLinkTags event, so the plugin needs no
| frontend build / Mix manifest.
*/
EventDispatcher::add_event_listener('leantime.*.afterLinkTags', function (): void {
    echo <<<'CSS'
<style>
    .ct-col-cost { width: 100px; white-space: nowrap; text-align: right; }
    .ct-over { color: var(--danger, #d9534f); font-weight: 600; }
    .ct-head-extra .ct-head-spacer {
        padding: 0; border: 0; height: 0; line-height: 0;
        background: transparent; max-height: 0; font-size: 0;
    }
    .ct-head-extra .ct-col-cost {
        padding: 6px 8px; font-size: 12px; text-align: right;
        border-bottom: 1px solid var(--grey, #ddd);
    }
    .ct-table-totals {
        display: flex; align-items: center; gap: 8px;
        margin: 6px 0 18px; font-size: 12px;
    }
    .ct-totals-label { font-weight: 700; }
    .ct-badge {
        display: inline-flex; align-items: center; gap: 3px;
        padding: 2px 8px; border-radius: 10px;
        background: var(--secondary-background, #eef2f4);
        color: var(--primary-font-color, #333);
    }
    .ct-badge-over { background: var(--danger, #d9534f); color: #fff; }
</style>
CSS;
});

/*
|--------------------------------------------------------------------------
| Persistence — sync our form fields when tickets are saved/deleted
|--------------------------------------------------------------------------
| The core service whitelist strips unknown POST fields, so the plugin owns
| a separate table and upserts here. Core dispatch happens only AFTER its
| create/edit/delete permission checks, so authorization is inherited.
*/

$saveCosts = function (?int $ticketId): void {
    if ($ticketId === null || $ticketId <= 0) {
        return;
    }

    // Our fields are only present when the rendered form included them
    // (full create/edit forms). Quick-add, sorting and status-only updates
    // don't carry them and must not wipe saved costs.
    if (! array_key_exists('costtracking_cost', $_POST)
        && ! array_key_exists('costtracking_actual_cost', $_POST)) {
        return;
    }

    app(Costs::class)->upsert(
        $ticketId,
        costtracking_amount($_POST['costtracking_cost'] ?? null),
        costtracking_amount($_POST['costtracking_actual_cost'] ?? null)
    );
};

EventDispatcher::add_event_listener(TicketCreated::class, function (TicketCreated $event) use ($saveCosts): void {
    $saveCosts($event->ticketId);
});

EventDispatcher::add_event_listener(TicketUpdated::class, function (TicketUpdated $event) use ($saveCosts): void {
    $saveCosts($event->ticketId);
});

EventDispatcher::add_event_listener(TicketDeleted::class, function (TicketDeleted $event): void {
    app(Costs::class)->deleteForTicket($event->ticketId);
});

/*
|--------------------------------------------------------------------------
| Ticket create/edit form — planned + actual cost inputs
|--------------------------------------------------------------------------
*/

EventDispatcher::add_event_listener('beforeEndRightColumn', function ($payload): void {
    $ticket = costtracking_context($payload, 'ticket');

    if ($ticket === null) {
        return;
    }

    $ticketId = (int) (is_array($ticket) ? ($ticket['id'] ?? 0) : ($ticket->id ?? 0));

    costtracking_render('formFields', [
        'costs' => $ticketId > 0 ? app(Costs::class)->getForTicket($ticketId) : null,
    ]);
});

/*
|--------------------------------------------------------------------------
| Ticket list/table views — two cost columns (full table) / inline badges
| (simple list) + per-group totals
|--------------------------------------------------------------------------
| TicketListFilter enriches row data once for the whole table; the template
| hooks only render. The full table (.ticketTable on showAll) is a DataTables
| instance with core JS relying on FIXED column indices, so cost cells are
| appended at row END and a matching 16-cell header row is prepended (DT 1.13
| picks the bottom header cell per column, so core labels/sorting survive).
| The simple list (showList) only has status+title cells, so badges render
| INSIDE the title cell. Milestone tables share the hook names but never pass
| through TicketListFilter — they are skipped entirely.
*/

EventDispatcher::add_filter_listener(TicketListFilter::class, function (array $ticketGroups): array {
    $costMap = app(Costs::class)->getAllTicketCosts();

    foreach ($ticketGroups as $groupKey => $group) {
        if (! is_array($group) || ! isset($group['items']) || ! is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as $i => $row) {
            $id = (int) (is_array($row) ? ($row['id'] ?? 0) : ($row->id ?? 0));
            $costs = $costMap[$id] ?? ['cost' => null, 'actualCost' => null];

            if (is_array($row)) {
                $ticketGroups[$groupKey]['items'][$i]['costtracking_cost'] = $costs['cost'];
                $ticketGroups[$groupKey]['items'][$i]['costtracking_actual_cost'] = $costs['actualCost'];
            } elseif (is_object($row)) {
                $row->costtracking_cost = $costs['cost'];
                $row->costtracking_actual_cost = $costs['actualCost'];
            }
        }
    }

    return $ticketGroups;
});

// showAll: prepend the 16-cell header row (14 spacers + cost labels at end).
EventDispatcher::add_event_listener('allTicketsTable.beforeHeadRow', function ($payload): void {
    $event = costtracking_event($payload);

    if (! str_contains($event, 'templates.showall.') || str_contains($event, 'milestone')) {
        return;
    }

    if (! costtracking_enriched(costtracking_context($payload, 'tickets'))) {
        return;
    }

    costtracking_render('tableHeaderExtra');
});

// showAll: two trailing <td>; showList: inline badges inside the title <td>.
EventDispatcher::add_event_listener('allTicketsTable.beforeRowEnd', function ($payload): void {
    $event = costtracking_event($payload);

    if (str_contains($event, 'milestone')) {
        return;
    }

    $tickets = costtracking_context($payload, 'tickets');
    $rowNum = costtracking_context($payload, 'rowNum');

    if (! is_array($tickets) || ! isset($tickets[$rowNum])) {
        return;
    }

    $costs = costtracking_row_costs($tickets[$rowNum]);

    if (str_contains($event, 'templates.showall.')) {
        if (! costtracking_enriched($tickets)) {
            return;
        }

        costtracking_render('tableCells', $costs);

        return;
    }

    if (str_contains($event, 'templates.showlist.')
        && ($costs['cost'] !== null || $costs['actualCost'] !== null)) {
        costtracking_render('inlineBadge', $costs);
    }
});

// Per-group totals rendered OUTSIDE the table (keeps DataTables intact).
EventDispatcher::add_event_listener('allTicketsTable.afterClose', function ($payload): void {
    $event = costtracking_event($payload);

    if (str_contains($event, 'milestone')
        || (! str_contains($event, 'templates.showall.') && ! str_contains($event, 'templates.showlist.'))) {
        return;
    }

    $tickets = costtracking_context($payload, 'tickets');

    if (! costtracking_enriched($tickets)) {
        return;
    }

    $planned = 0.0;
    $actual = 0.0;

    foreach ($tickets as $row) {
        $costs = costtracking_row_costs($row);
        $planned += (float) $costs['cost'];
        $actual += (float) $costs['actualCost'];
    }

    costtracking_render('tableTotals', [
        'planned' => $planned,
        'actual' => $actual,
    ]);
});

/*
|--------------------------------------------------------------------------
| Kanban card badge
|--------------------------------------------------------------------------
*/

EventDispatcher::add_event_listener('ticketCard.meta', function ($payload): void {
    $ticket = costtracking_context($payload, 'ticket');

    if ($ticket === null) {
        return;
    }

    $ticketId = (int) (is_array($ticket) ? ($ticket['id'] ?? 0) : ($ticket->id ?? 0));
    $costs = app(Costs::class)->getForTicket($ticketId);

    if ($costs === null || ($costs['cost'] === null && $costs['actualCost'] === null)) {
        return;
    }

    costtracking_render('kanbanBadge', $costs);
});

/*
|--------------------------------------------------------------------------
| Project page — "Cost" tab with rollup vs. project budget
|--------------------------------------------------------------------------
*/

EventDispatcher::add_event_listener('projectTabsList', function (): void {
    costtracking_render('projectTabLink');
});

EventDispatcher::add_event_listener('projectTabsContent', function (): void {
    $projectId = (int) session('currentProject');

    if ($projectId <= 0) {
        return;
    }

    $project = app(ProjectService::class)->getProject($projectId);

    if (! is_array($project) || ! isset($project['id'])) {
        return;
    }

    $scope = (($_GET['costScope'] ?? null) === Costs::SCOPE_SUBTASKS)
        ? Costs::SCOPE_SUBTASKS
        : Costs::SCOPE_TOPLEVEL;

    costtracking_render('projectTab', [
        'project' => $project,
        'rollup' => app(Costs::class)->getProjectRollup($projectId, $scope),
        'scope' => $scope,
        'budget' => (float) ($project['dollarBudget'] ?? 0),
    ]);
});

/*
|--------------------------------------------------------------------------
| Project hub cards — total cost per project (batch loaded)
|--------------------------------------------------------------------------
*/

EventDispatcher::add_event_listener('projectCard.afterProgress', function ($payload): void {
    $project = costtracking_context($payload, 'project');

    if (! is_array($project) || empty($project['id'])) {
        return;
    }

    $rollups = app(Costs::class)->getAllProjectRollups();
    $rollup = $rollups[(int) $project['id']] ?? null;

    if ($rollup === null || ((float) $rollup['cost'] === 0.0 && (float) $rollup['actualCost'] === 0.0)) {
        return;
    }

    costtracking_render('projectCardCost', $rollup);
});
