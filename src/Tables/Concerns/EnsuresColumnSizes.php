<?php

namespace Laravel\Octane\Tables\Concerns;


use Laravel\Octane\Exceptions\ValueTooLargeForColumnException;
use Swoole\Table;

trait EnsuresColumnSizes
{
    /**
     * Ensures the given column value is within the given size.
     *
     * @return \Closure
     */
    protected function ensureColumnsSize()
    {
        return function ($value, $column) {
            if (! array_key_exists($column, $this->columns)) {
                return;
            }

            [$type, $size] = $this->columns[$column];

            if ($type == Table::TYPE_STRING && strlen($value) > $size) {
                throw new ValueTooLargeForColumnException(sprintf(
                    'Value [%s...] is too large for [%s] column.',
                    substr($value, 0, 20),
                    $column,
                ));
            }
        };
    }
}
