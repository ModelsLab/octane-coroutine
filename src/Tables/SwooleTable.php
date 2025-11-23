<?php

namespace Laravel\Octane\Tables;


use Swoole\Table;

class SwooleTable extends Table
{
    use Concerns\EnsuresColumnSizes;

    /**
     * The table columns.
     *
     * @var array
     */
    protected $columns;

    /**
     * Set the data type and size of the columns.
     */
    public function column(string $name, int $type, int $size = 0): bool
    {
        $this->columns[$name] = [$type, $size];

        return parent::column($name, $type, $size);
    }

    /**
     * Update a row of the table.
     */
    public function set(string $key, array $values): bool
    {
        $callback = $this->ensureColumnsSize();

        foreach ($values as $key => $value) {
            $callback($value, $key);
        }

        return parent::set($key, $values);
    }
}
