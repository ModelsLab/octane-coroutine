<?php

namespace Tests\Unit;

use Laravel\Octane\Tables\OpenSwooleTable;
use Laravel\Octane\Tables\SwooleTable;
use PHPUnit\Framework\TestCase;
use Swoole\Table;

class SwooleTableTest extends TestCase
{
    /**
     * @dataProvider tableClasses
     */
    public function test_set_preserves_the_requested_row_key(string $tableClass): void
    {
        if (! class_exists(Table::class)) {
            $this->markTestSkipped('Swoole table support is required.');
        }

        /** @var \Swoole\Table $table */
        $table = new $tableClass(16, 1);
        $table->column('worker_pid', Table::TYPE_INT);
        $table->column('time', Table::TYPE_INT);
        $table->column('fd', Table::TYPE_INT);
        $table->create();

        $this->assertTrue($table->set('123', [
            'worker_pid' => 456,
            'time' => 789,
            'fd' => 10,
        ]));

        $this->assertSame(456, $table->get('123', 'worker_pid'));
        $this->assertSame(789, $table->get('123', 'time'));
        $this->assertSame(10, $table->get('123', 'fd'));
        $this->assertFalse($table->exist('fd'));
    }

    public static function tableClasses(): array
    {
        return [
            'swoole' => [SwooleTable::class],
            'openswoole wrapper' => [OpenSwooleTable::class],
        ];
    }
}
