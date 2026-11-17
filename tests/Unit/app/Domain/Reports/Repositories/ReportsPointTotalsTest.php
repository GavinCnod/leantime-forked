<?php

namespace Unit\app\Domain\Reports\Repositories;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Leantime\Core\Db\DatabaseHelper;
use Leantime\Domain\Reports\Repositories\Reports;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Unit\TestCase;

/**
 * addReport() fits story-point totals into the integer zp_stats columns.
 *
 * Story points can be fractional (the "< 2min" effort is 0.5), which MySQL rounds silently but
 * PostgreSQL rejects with SQLSTATE[22P02], aborting the nightly ingestion. addReport() rounds
 * before inserting so both drivers accept the row; NULLs keep their meaning.
 */
class ReportsPointTotalsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * Runs addReport() against a connection that captures the inserted row.
     *
     * @return array<string, mixed> The values handed to the query builder.
     */
    private function insertRow(object $report): array
    {
        $inserted = [];
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('insert')->once()->andReturnUsing(function (array $values) use (&$inserted): bool {
            $inserted = $values;

            return true;
        });

        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('table')->once()->with('zp_stats')->andReturn($builder);

        $repository = (new \ReflectionClass(Reports::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Reports::class, 'db'))->setValue($repository, $connection);
        (new \ReflectionProperty(Reports::class, 'dbHelper'))->setValue($repository, Mockery::mock(DatabaseHelper::class));

        $repository->addReport($report);

        return $inserted;
    }

    private function report(array $overrides = []): object
    {
        return (object) array_merge([
            'sprintId' => -1,
            'projectId' => 4,
            'date' => '2026-11-14',
            'sum_todos' => 3,
            'sum_open_todos' => 1,
            'sum_progres_todos' => 1,
            'sum_closed_todos' => 1,
            'sum_planned_hours' => 4.0,
            'sum_estremaining_hours' => 2.0,
            'sum_logged_hours' => 1.0,
            'sum_points' => 0.5,
            'sum_points_done' => 0.5,
            'sum_points_progress' => 2.5,
            'sum_points_open' => 0.0,
            'sum_todos_xs' => 1,
            'sum_todos_s' => 0,
            'sum_todos_m' => 0,
            'sum_todos_l' => 0,
            'sum_todos_xl' => 0,
            'sum_todos_xxl' => 0,
            'sum_todos_none' => 2,
            'daily_avg_hours_booked_todo' => 0.33,
            'daily_avg_hours_booked_point' => 2.0,
            'daily_avg_hours_planned_todo' => 1.33,
            'daily_avg_hours_planned_point' => 8.0,
            'daily_avg_hours_remaining_point' => 4.0,
            'daily_avg_hours_remaining_todo' => 0.67,
            'sum_teammembers' => 2,
        ], $overrides);
    }

    public function test_fractional_point_totals_are_rounded(): void
    {
        $row = $this->insertRow($this->report());

        $this->assertSame(1, $row['sum_points']);            // 0.5 rounds to 1
        $this->assertSame(1, $row['sum_points_done']);        // 0.5 rounds to 1
        $this->assertSame(3, $row['sum_points_progress']);    // 2.5 rounds to 3
        $this->assertSame(0, $row['sum_points_open']);
    }

    public function test_integer_totals_are_unchanged(): void
    {
        $row = $this->insertRow($this->report([
            'sum_points' => 13,
            'sum_points_done' => 8,
            'sum_points_progress' => 5,
            'sum_points_open' => 0,
        ]));

        $this->assertSame(13, $row['sum_points']);
        $this->assertSame(8, $row['sum_points_done']);
        $this->assertSame(5, $row['sum_points_progress']);
        $this->assertSame(0, $row['sum_points_open']);
    }

    public function test_missing_totals_stay_null(): void
    {
        $row = $this->insertRow($this->report([
            'sum_points' => null,
            'sum_points_done' => null,
            'sum_points_progress' => null,
            'sum_points_open' => null,
        ]));

        $this->assertNull($row['sum_points']);
        $this->assertNull($row['sum_points_done']);
        $this->assertNull($row['sum_points_progress']);
        $this->assertNull($row['sum_points_open']);
    }
}
