<?php

namespace Laravel\Octane\Swoole\Database;

use Illuminate\Database\MySqlConnection;
use PDO;

/**
 * Binds integer parameters as strings.
 *
 * MySQL 9.0.1 re-prepares a statement server-side on EVERY execute that
 * carries an integer-typed binary-protocol parameter (measured:
 * PDO::PARAM_INT -> Com_stmt_reprepare +1 per execute, PDO::PARAM_STR with
 * the same value -> 0, identical results). No extra round trips - the server
 * transparently parses and plans the whole statement again, costing ~15-50us
 * of server CPU per execute. Production measured 226 reprepares/second, 54%
 * of all statement executes.
 *
 * A string PARAMETER stays exact: the server converts it to the column's
 * type with full precision at execute time, so plans, index use, and results
 * are unchanged - verified for adjacent bigints beyond 2^53, where a quoted
 * LITERAL would compare as DOUBLE and merge distinct values. Do not extend
 * this reasoning to literals interpolated into SQL text.
 *
 * Two semantic footnotes. An int bound against a VARCHAR column previously
 * compared numerically ('05' matched 5) and defeated the index; it now
 * compares as a string - every id-like varchar column in production was
 * scanned for non-canonical numeric values before shipping (zero found).
 * And the dangerous direction remains the old one: an int parameter against
 * a varchar column disables index use - see dreambooth_models.user_id.
 */
class MySqlStringBindingConnection extends MySqlConnection
{
    /**
     * @param  \PDOStatement  $statement
     * @param  array  $bindings
     */
    public function bindValues($statement, $bindings): void
    {
        foreach ($bindings as $key => $value) {
            // Identical to Illuminate\Database\Connection::bindValues except
            // integers: stringified and sent as PARAM_STR.
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                is_int($value) ? (string) $value : $value,
                is_resource($value) ? PDO::PARAM_LOB : PDO::PARAM_STR,
            );
        }
    }
}
