<?php

declare(strict_types=1);

namespace PluginXchange\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PluginXchange\Http\ApiException;
use PluginXchange\Http\Pagination;

final class PaginationTest extends TestCase
{
    public function testDefaults(): void
    {
        $p = Pagination::fromQuery([]);
        $this->assertSame(1, $p->page);
        $this->assertSame(20, $p->perPage);
        $this->assertSame(0, $p->offset());
    }

    public function testPerPageIsCappedAt100AndOffsetIsComputed(): void
    {
        $p = Pagination::fromQuery(['page' => '3', 'per_page' => '500']);
        $this->assertSame(100, $p->perPage);
        $this->assertSame(200, $p->offset());
    }

    public function testInvalidValuesAre422(): void
    {
        foreach ([['page' => '0'], ['per_page' => '-1'], ['per_page' => 'abc'], ['page' => ['1']]] as $query) {
            try {
                Pagination::fromQuery($query);
                $this->fail('Expected ApiException for ' . json_encode($query));
            } catch (ApiException $e) {
                $this->assertSame(422, $e->status);
            }
        }
    }
}
