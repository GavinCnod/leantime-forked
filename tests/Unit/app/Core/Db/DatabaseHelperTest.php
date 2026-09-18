<?php

namespace Unit\app\Core\Db;

use Illuminate\Database\ConnectionInterface;
use Leantime\Core\Db\DatabaseHelper;
use Unit\TestCase;

/**
 * Regression tests for parseStatusGroups() cross-database handling.
 *
 * Empty status groups used to be emitted as the MySQL-only literal "IN(FALSE)",
 * which PostgreSQL rejects and which the old parser silently turned into an
 * unintended default status. Empty groups are now the portable "IN (NULL)"
 * marker and must resolve to an empty array, never a defaulted one.
 */
class DatabaseHelperTest extends TestCase
{
    private function makeHelper(): DatabaseHelper
    {
        return new DatabaseHelper($this->createMock(ConnectionInterface::class));
    }

    public function test_parses_numeric_status_groups(): void
    {
        $result = $this->makeHelper()->parseStatusGroups([
            'DONE' => 'IN(0,-1)',
            'INPROGRESS' => 'IN (1, 2, 4)',
            'NEW' => 'IN(3)',
        ]);

        $this->assertSame([0, -1], $result['DONE']);
        $this->assertSame([1, 2, 4], $result['INPROGRESS']);
        $this->assertSame([3], $result['NEW']);
    }

    public function test_empty_groups_resolve_to_empty_array(): void
    {
        $result = $this->makeHelper()->parseStatusGroups([
            'DONE' => 'IN (NULL)',
            'INPROGRESS' => 'IN(NULL)',
            'NEW' => 'IN()',
            'ALLOPEN' => 'IN ()',
        ]);

        $this->assertSame([], $result['DONE']);
        $this->assertSame([], $result['INPROGRESS']);
        $this->assertSame([], $result['NEW']);
        $this->assertSame([], $result['ALLOPEN']);
    }

    public function test_unrecognized_fragment_resolves_to_empty_array(): void
    {
        $result = $this->makeHelper()->parseStatusGroups([
            'DONE' => 'something-else',
        ]);

        $this->assertSame([], $result['DONE']);
    }
}
