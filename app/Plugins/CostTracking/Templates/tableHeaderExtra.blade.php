{{--
    Extra thead row for the full ticket table on showAll
    (allTicketsTable.beforeHeadRow).

    The core row keeps its 14 <th> and renders AFTER this one. DataTables 1.13
    builds its column model from the FIRST thead row (16 columns here, matching
    the 16 body cells) but takes the bottom cell per column for labels/sorting,
    so core columns are untouched and only the last two belong to the plugin.
    The 14 spacer cells collapse visually.
--}}
<tr class="ct-head-extra">
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-head-spacer"></th>
    <th class="ct-col-cost">{!! __('costtracking.planned_cost') !!}</th>
    <th class="ct-col-cost">{!! __('costtracking.actual_cost') !!}</th>
</tr>
