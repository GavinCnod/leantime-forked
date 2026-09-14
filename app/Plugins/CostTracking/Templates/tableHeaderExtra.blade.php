{{--
    Two cost <th> appended INSIDE the core header row on showAll
    (allTicketsTable.afterHeadCell), so the header stays a single row and
    lines up 1:1 with the two trailing <td> that beforeRowEnd adds to each
    data row. Injected after the core's last <th> (the no-sort actions cell).
--}}
<th class="ct-col-cost">{!! __('costtracking.planned_cost') !!}</th>
<th class="ct-col-cost">{!! __('costtracking.actual_cost') !!}</th>
