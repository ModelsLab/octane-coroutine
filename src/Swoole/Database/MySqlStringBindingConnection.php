<?php

namespace Laravel\Octane\Swoole\Database;

use Illuminate\Database\MySqlConnection;
use PDO;

/**
 * Binds integer parameters as strings.
 *
 * MySQL 9.0.1 re-prepares a statement on EVERY execute that carries an
 * integer-typed binary-protocol parameter (measured: PDO::PARAM_INT ->
 * Com_stmt_reprepare +1 per execute, PDO::PARAM_STR with the same value ->
 * 0, identical results). mysqlnd hides the failure by silently re-preparing
 * and re-executing, so every int-bound query pays two extra round trips -
 * production measured 226 reprepares/second, 54% of all statement executes.
 *
 * String-typed parameters are cast once by the server at optimization time,
 * exactly like a quoted literal, so plans, index use, and results are
 * unchanged. (The dangerous direction is the opposite one: an int parameter
 * compared against a varchar column disables index use - see the
 * dreambooth_models.user_id incident.)
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
