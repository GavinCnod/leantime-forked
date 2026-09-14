<?php

namespace Leantime\Plugins\CostTracking\Repositories;

use Illuminate\Database\ConnectionInterface;
use Leantime\Core\Db\Db as DbCore;
use Leantime\Plugins\CostTracking\Services\CostTracking;

/**
 * Data access for the zp_ticket_costs plugin table.
 *
 * Rollup queries always join zp_tickets so costs can be scoped to a project and
 * split by top-level tasks vs. subtasks (milestones are never counted).
 */
class Costs
{
    public const SCOPE_TOPLEVEL = 'toplevel';

    public const SCOPE_SUBTASKS = 'subtasks';

    private ConnectionInterface $connection;

    /** @var array<int, object>|null request-level cache for single lookups */
    private ?array $ticketCostMap = null;

    /** @var array<int, array<string, float|int>>|null request-level cache for project rollups */
    private ?array $projectRollupMap = null;

    public function __construct(DbCore $db)
    {
        $this->connection = $db->getConnection();
    }

    /**
     * Insert or update the cost values for one ticket.
     */
    public function upsert(int $ticketId, ?float $cost, ?float $actualCost): bool
    {
        $result = $this->connection->table(CostTracking::TABLE)->updateOrInsert(
            ['ticketId' => $ticketId],
            [
                'cost' => $cost,
                'actualCost' => $actualCost,
                'modified' => gmdate('Y-m-d H:i:s'),
            ]
        );

        $this->ticketCostMap = null;
        $this->projectRollupMap = null;

        return (bool) $result;
    }

    public function deleteForTicket(int $ticketId): void
    {
        $this->connection->table(CostTracking::TABLE)->where('ticketId', $ticketId)->delete();

        $this->ticketCostMap = null;
        $this->projectRollupMap = null;
    }

    /**
     * @return array{cost: ?float, actualCost: ?float}|null
     */
    public function getForTicket(int $ticketId): ?array
    {
        $map = $this->getTicketCostMap();

        if (! isset($map[$ticketId])) {
            return null;
        }

        return $this->normalizeRow($map[$ticketId]);
    }

    /**
     * All cost rows keyed by ticketId. Loaded once per request for batch
     * enrichment (list views, kanban cards).
     *
     * @return array<int, array{cost: ?float, actualCost: ?float}>
     */
    public function getAllTicketCosts(): array
    {
        return array_map(fn ($row) => $this->normalizeRow($row), $this->getTicketCostMap());
    }

    /**
     * Aggregated costs for one project.
     *
     * @return array{cost: float, actualCost: float, ticketCount: int}
     */
    public function getProjectRollup(int $projectId, string $scope = self::SCOPE_TOPLEVEL): array
    {
        $rollups = $this->getAllProjectRollups($scope);

        return $rollups[$projectId] ?? ['cost' => 0.0, 'actualCost' => 0.0, 'ticketCount' => 0];
    }

    /**
     * Project rollups for ALL projects in one query, keyed by projectId.
     * Used by project cards/project hub to avoid N+1 queries.
     *
     * @return array<int, array{cost: float, actualCost: float, ticketCount: int}>
     */
    public function getAllProjectRollups(string $scope = self::SCOPE_TOPLEVEL): array
    {
        $cacheKey = $scope;

        if ($this->projectRollupMap !== null && isset($this->projectRollupMap[$cacheKey])) {
            return $this->projectRollupMap[$cacheKey];
        }

        $rows = $this->buildRollupQuery($scope)
            ->addSelect('t.projectId')
            ->groupBy('t.projectId')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->projectId] = [
                'cost' => (float) $row->cost,
                'actualCost' => (float) $row->actualCost,
                'ticketCount' => (int) $row->ticketCount,
            ];
        }

        $this->projectRollupMap[$cacheKey] = $map;

        return $map;
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function buildRollupQuery(string $scope)
    {
        $query = $this->connection->table(CostTracking::TABLE.' as c')
            ->join('zp_tickets as t', 't.id', '=', 'c.ticketId')
            ->selectRaw('COALESCE(SUM(c.cost), 0) AS cost')
            ->selectRaw('COALESCE(SUM(c.actualCost), 0) AS actualCost')
            ->selectRaw('COUNT(c.ticketId) AS ticketCount');

        // type is '' / NULL for regular tasks; milestones and (optionally)
        // subtasks are excluded so money is never double-counted.
        if ($scope === self::SCOPE_SUBTASKS) {
            $query->whereRaw("COALESCE(NULLIF(t.type, ''), 'task') = ?", ['subtask']);
        } else {
            $query->whereRaw("COALESCE(NULLIF(t.type, ''), 'task') NOT IN (?, ?)", ['subtask', 'milestone']);
        }

        return $query;
    }

    /**
     * @return array<int, object>
     */
    private function getTicketCostMap(): array
    {
        if ($this->ticketCostMap === null) {
            $this->ticketCostMap = [];

            $rows = $this->connection->table(CostTracking::TABLE)->get();
            foreach ($rows as $row) {
                $this->ticketCostMap[(int) $row->ticketId] = $row;
            }
        }

        return $this->ticketCostMap;
    }

    /**
     * @return array{cost: ?float, actualCost: ?float}
     */
    private function normalizeRow(object $row): array
    {
        return [
            'cost' => $row->cost !== null ? (float) $row->cost : null,
            'actualCost' => $row->actualCost !== null ? (float) $row->actualCost : null,
        ];
    }
}
