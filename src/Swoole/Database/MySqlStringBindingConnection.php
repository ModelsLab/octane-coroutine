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
 *
 * Statement-cache semantic deltas (measured and accepted):
 * - A bindings array SHORTER than the placeholder count used to throw
 *   HY093; on a cached statement the leftover value from the previous
 *   execution fills the gap silently. Query-builder SQL always binds
 *   fully; hand-built SQL with conditional bindings must too.
 * - prepared() now runs on write paths and cache hits, so the
 *   StatementPrepared event fires there as well (no app listens today).
 * - A cached statement pins its last bound values (and any LOB stream)
 *   until the same SQL runs again, eviction, flush, or the pool's
 *   max_lifetime recycle.
 */
class MySqlStringBindingConnection extends MySqlConnection
{
    /**
     * Prepared statements cached per PDO, keyed by SQL text, LRU-capped.
     *
     * Laravel prepares every query fresh: one COM_STMT_PREPARE network round
     * trip plus client and server parse work per query (7.5% of gpulab's PHP
     * CPU, 4% of the frontend's, was PDO::prepare). Statement handles are
     * per-session, so the cache lives on this Connection - which the octane
     * pool hands to exactly one coroutine at a time - and is flushed whenever
     * the underlying PDO is swapped (disconnect, reconnector).
     *
     * @var array<string, \PDOStatement>
     */
    protected array $statementCache = [];

    protected ?bool $statementCacheEnabled = null;

    protected ?int $statementCacheLimit = null;

    /**
     * @param  \PDOStatement  $statement
     * @param  array  $bindings
     */
    public function bindValues($statement, $bindings): void
    {
        if (! $this->stringBindingsAreEnabled()) {
            parent::bindValues($statement, $bindings);

            return;
        }

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

    protected ?bool $stringBindingsEnabled = null;

    protected function stringBindingsAreEnabled(): bool
    {
        return $this->stringBindingsEnabled ??= $this->octaneFlag('octane.mysql_string_bindings');
    }

    /**
     * Read a boolean octane config flag, defaulting when no application
     * container exists (pure-PDO contexts, very early boot).
     */
    protected function octaneFlag(string $key, bool $default = true): bool
    {
        try {
            $value = config($key, $default);
        } catch (\Throwable) {
            return $default;
        }

        return (bool) (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * {@inheritdoc}
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        if (! $this->statementCacheIsEnabled()) {
            return parent::select($query, $bindings, $useReadPdo);
        }

        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $pdo = $this->getPdoForSelect($useReadPdo);
            $statement = $this->cachedPrepare($pdo, $query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            try {
                $this->executeCached($statement, $pdo, $query, $bindings);

                return $statement->fetchAll();
            } finally {
                // Always drain: a cached statement no longer frees its result
                // in a destructor, and on the unbuffered connections two of
                // the apps run, an abandoned result set wedges the session
                // (2014 on every later query) - closeCursor un-wedges it.
                try {
                    $statement->closeCursor();
                } catch (\Throwable) {
                    // The connection may already be gone; nothing to drain.
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     *
     * MySqlConnection overrides insert() past statement() to capture
     * lastInsertId, so it needs its own cached variant - this is the
     * hottest write path there is (every Eloquent create()).
     */
    public function insert($query, $bindings = [], $sequence = null)
    {
        if (! $this->statementCacheIsEnabled()) {
            return parent::insert($query, $bindings, $sequence);
        }

        return $this->run($query, $bindings, function ($query, $bindings) use ($sequence) {
            if ($this->pretending()) {
                return true;
            }

            $pdo = $this->getPdo();
            $statement = $this->cachedPrepare($pdo, $query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            $result = $this->executeCached($statement, $pdo, $query, $bindings);

            $this->lastInsertId = $this->getPdo()->lastInsertId($sequence);

            $statement->closeCursor();

            return $result;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function statement($query, $bindings = [])
    {
        if (! $this->statementCacheIsEnabled()) {
            return parent::statement($query, $bindings);
        }

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $pdo = $this->getPdo();
            $statement = $this->cachedPrepare($pdo, $query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            $result = $this->executeCached($statement, $pdo, $query, $bindings);

            $statement->closeCursor();

            return $result;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function affectingStatement($query, $bindings = [])
    {
        if (! $this->statementCacheIsEnabled()) {
            return parent::affectingStatement($query, $bindings);
        }

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $pdo = $this->getPdo();
            $statement = $this->cachedPrepare($pdo, $query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            $this->executeCached($statement, $pdo, $query, $bindings);

            $this->recordsHaveBeenModified(
                ($count = $statement->rowCount()) > 0
            );

            $statement->closeCursor();

            return $count;
        });
    }

    /**
     * The cache key must identify the SQL AND the server session: the read
     * PDO is a different session than the write PDO, and statement handles
     * do not survive a session swap.
     */
    protected function cachedPrepare(PDO $pdo, string $query): \PDOStatement
    {
        $key = spl_object_id($pdo).'|'.$query;

        if (isset($this->statementCache[$key])) {
            $statement = $this->statementCache[$key];

            // Re-append for LRU recency, clear any leftover result state,
            // and re-run prepared() so the fetch mode and StatementPrepared
            // event behave exactly as they would on a fresh prepare.
            unset($this->statementCache[$key]);
            $this->statementCache[$key] = $statement;
            $statement->closeCursor();

            return $this->prepared($statement);
        }

        try {
            $statement = $this->prepared($pdo->prepare($query));
        } catch (\PDOException $e) {
            // 1461: the server hit max_prepared_stmt_count. This connection's
            // cache is part of the pressure - drop it all and retry once with
            // an empty cache before surfacing the error.
            if (($e->errorInfo[1] ?? null) !== 1461) {
                throw $e;
            }

            $this->flushStatementCache();
            $statement = $this->prepared($pdo->prepare($query));
        }

        $this->statementCache[$key] = $statement;

        if (count($this->statementCache) > $this->statementCacheLimit()) {
            array_shift($this->statementCache);
        }

        return $statement;
    }

    /**
     * Execute, healing MySQL error 1615 ("Prepared statement needs to be
     * re-prepared") once by evicting and re-preparing. MySQL re-prepares
     * server-side transparently on metadata change; 1615 only surfaces when
     * table_definition_cache thrashes, and a fresh prepare resolves it.
     *
     * @param-out \PDOStatement $statement
     */
    protected function executeCached(\PDOStatement &$statement, PDO $pdo, string $query, array $bindings): bool
    {
        try {
            return $statement->execute();
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== 1615) {
                throw $e;
            }

            unset($this->statementCache[spl_object_id($pdo).'|'.$query]);

            $statement = $this->cachedPrepare($pdo, $query);
            $this->bindValues($statement, $this->prepareBindings($bindings));

            return $statement->execute();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setPdo($pdo)
    {
        $this->flushStatementCache();

        return parent::setPdo($pdo);
    }

    /**
     * {@inheritdoc}
     */
    public function setReadPdo($pdo)
    {
        $this->flushStatementCache();

        return parent::setReadPdo($pdo);
    }

    /**
     * Statement handles belong to the server session behind the PDO that
     * prepared them; when any PDO is swapped they must all go. Covers
     * disconnect() and the pool reconnector, both of which route through
     * setPdo()/setReadPdo().
     */
    public function flushStatementCache(): void
    {
        $this->statementCache = [];
    }

    protected function statementCacheIsEnabled(): bool
    {
        return $this->statementCacheEnabled ??= $this->octaneFlag('octane.mysql_statement_cache');
    }

    protected function statementCacheLimit(): int
    {
        try {
            $size = (int) config('octane.mysql_statement_cache_size', 64);
        } catch (\Throwable) {
            $size = 64;
        }

        return $this->statementCacheLimit ??= max(1, $size);
    }
}
