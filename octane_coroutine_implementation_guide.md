# Laravel Octane Swoole Coroutine Implementation Guide

## 1. Context & Objective

**Goal:** Enable high-concurrency handling of blocking I/O requests (e.g., long-running API calls, sleeps) using Swoole Coroutines.

**Why:**
- **Current State:** Octane uses a "One Worker = One Request" model. A 5-second blocking request holds the entire worker process, reducing throughput to ~0.2 req/sec per worker.
- **Desired State:** "One Worker = Many Concurrent Requests". Using Swoole Coroutines (`enable_coroutine => true`), a single worker should switch context during blocking I/O, handling thousands of concurrent requests.

**The Problem:**
Laravel Octane is currently architected for **Process-Level Concurrency** (RoadRunner/Swoole Workers), not **Coroutine-Level Concurrency**.
- **Global State:** It relies on a global `$workerState` object.
- **Single Application Instance:** It maintains one Laravel Application instance per worker.
- **Race Conditions:** Enabling coroutines causes multiple requests to access the same Application instance and global state simultaneously, leading to race conditions, data corruption, and crashes.

### The Specific Error (Evidence)
When enabling `enable_coroutine => true` in Octane v2.13.1, workers crash with:

**Error 1: Timer Table Null Pointer**
```
Fatal error: Uncaught TypeError: Laravel\Octane\Tables\SwooleTable::set(): 
Argument #1 ($key) must be of type string, null given
File: vendor/laravel/octane/bin/swoole-server
Line: 115
Code: $workerState->timerTable->set($workerState->workerId, ...);
```
*Cause:* `$workerState->workerId` is initialized in the main worker process but is `null` inside the coroutine context of a request.

**Error 2: Worker Handle Null Pointer**
```
Fatal error: Uncaught Error: Call to a member function handle() on null
File: vendor/laravel/octane/bin/swoole-server
Line: 123
Code: $workerState->worker->handle(...);
```
*Cause:* `$workerState->worker` is also `null` in the coroutine context. The global `$workerState` object is not coroutine-safe.

---

## 2. Reference Architecture: How Hyperf Does It

[Hyperf](https://github.com/hyperf/hyperf) is a high-performance PHP framework built specifically for Swoole/Coroutines. We can learn from its architecture.

### Hyperf's Approach
1.  **Context Manager:** Instead of global variables, Hyperf uses `Hyperf\Context\Context` (wrapper around `Swoole\Coroutine::getContext()`) to store request-specific data.
2.  **Connection Pooling:** Database and Redis connections are pulled from a pool for each coroutine and released after use.
3.  **Coroutine-Safe Container:** The DI container is designed to handle coroutine scopes.

**Example (Hyperf Context Usage):**
```php
// Storing data in current coroutine context
Context::set('request', $request);

// Retrieving data (safe across concurrent requests)
$request = Context::get('request');
```

**Lesson for Octane Fork:** We must replicate this "Context" pattern. We cannot rely on `$workerState` properties directly in the request handler.

---

## 3. Technical Architecture Changes

To support coroutines, we must move from **Worker-Global State** to **Coroutine-Local State**.

### Core Concept: Application Pooling
Since the Laravel Application container is not thread-safe/coroutine-safe (it holds request-specific state like `request`, `auth`, `session`), we cannot share one instance across concurrent coroutines.

**Solution:** Implement a **Pool of Application Clients** within each worker.
- **Start:** Pop a Client (Application Sandbox) from the pool.
- **Process:** Run the request in that isolated sandbox.
- **End:** Reset the sandbox (flush bindings) and push it back to the pool.

### Core Concept: Coroutine Context
We must replace global variable access with `Swoole\Coroutine::getContext()`.

---

## 4. Implementation Steps (Fork Guide)

### Step A: Modify `WorkerState`
The `WorkerState` class currently holds a single `$client`. It needs to hold a **Pool**.

**File:** `vendor/laravel/octane/src/Swoole/WorkerState.php`
```php
class WorkerState
{
    // ...
    public $clientPool; // New: \Swoole\Coroutine\Channel
    // Remove public $client; (or keep as a fallback/factory)
}
```

### Step B: Initialize the Pool
In the worker start handler, instead of creating one client, create a pool of them.

**File:** `vendor/laravel/octane/src/Swoole/Handlers/OnWorkerStart.php`
```php
// Pseudo-code logic
use Swoole\Coroutine\Channel;

// Create a channel to act as a pool (capacity = max concurrent requests)
$pool = new Channel(2000); 

// Pre-fill the pool with Octane Clients (Application instances)
for ($i = 0; $i < 50; $i++) { // Start with 50, grow as needed or fixed size
    $client = new \Laravel\Octane\Swoole\SwooleClient(...);
    // Boot the application once
    $client->application->bootstrapWith([...]);
    $pool->push($client);
}

$workerState->clientPool = $pool;
```

### Step C: Update Request Handler (`swoole-server`)
This is the critical change. We must isolate the execution context.

**File:** `vendor/laravel/octane/bin/swoole-server`

**Current Logic (Broken):**
```php
$server->on('request', function ($request, $response) use ($workerState) {
    $workerState->worker->handle(
        $workerState->client->marshalRequest(...) // Race condition on ->client
    );
});
```

**New Logic (Coroutine-Safe):**
```php
$server->on('request', function ($request, $response) use ($workerState) {
    // 1. Get Coroutine Context
    $context = \Swoole\Coroutine::getContext();

    // 2. Acquire Client from Pool (Yields/Blocks if empty)
    // This is the "magic" - if all apps are busy, this coroutine waits here
    $client = $workerState->clientPool->pop();
    
    // 3. Store in Context (for access elsewhere if needed)
    $context['client'] = $client;
    $context['worker_id'] = $workerState->workerId; // Copy from global to local

    try {
        // 4. Handle Request using the Isolated Client
        // Note: We use $client->worker, NOT $workerState->worker
        $client->worker->handle(
            $client->marshalRequest(new RequestContext([
                'swooleRequest' => $request,
                'swooleResponse' => $response,
                // ...
            ]))
        );
    } finally {
        // 5. Release Client back to Pool
        // CRITICAL: Must happen even if app crashes
        $workerState->clientPool->push($client);
    }
});
```

### Step D: Fix The Watchdog (`timerTable`)
The current watchdog tracks request duration by `workerId`. This breaks with concurrency (1 worker = N requests).

**Problem:** `timerTable->set($workerId, ...)` overwrites the entry for every new concurrent request.
**Solution:** 
1.  **Disable Watchdog** for coroutine mode (Easiest).
2.  **Refactor Watchdog** to use `Coroutine ID` (Cid) as the key instead of `Worker ID`.

**File:** `vendor/laravel/octane/bin/swoole-server`
```php
// If keeping watchdog:
$timerTable->set(\Swoole\Coroutine::getCid(), [ ... ]);
```

---

## 5. Verification & Testing

### 1. Unit Tests
- Create a test that spawns 2 coroutines.
- Assert that they receive *different* Application instances.

### 2. Stress Test (The "Bug Fix" Verification)
Use `wrk` with high concurrency to verify the crash is gone and throughput is high.

**Command:**
```bash
# 2000 concurrent connections, 5s sleep
wrk -t12 -c2000 -d30s http://localhost:8000/swoole-test
```

**Success Criteria:**
- **No Crashes:** Server logs are clean (no `null` pointer exceptions).
- **High Throughput:** 
  - Theoretical: 24 workers * (1/5s) = 4.8 req/sec (OLD)
  - Target: ~400+ req/sec (depending on pool size and overhead).
- **Concurrency:** `/stats` endpoint should show `worker_concurrency > 1`.

---

## 6. Risks & Considerations

1.  **Memory Usage:** Each Client in the pool is a full Laravel Application. 
    - 100 concurrent requests = 100 App instances in memory.
    - **Mitigation:** Tune pool size carefully. Use `max_coroutine` to limit concurrency.
2.  **Database Connections:** Each App instance needs its own DB connection (or must check one out from a pool).
    - **Critical:** Ensure `DB::disconnect()` is called or connections are pooled correctly between coroutine switches. Swoole's built-in hook flags (`SWOOLE_HOOK_TCP`) usually handle this, but Laravel's PDO usage needs verification.
3.  **Global State Pollution:** Any static variables in your user code (or libraries) will be shared across coroutines in the same worker.
    - **Rule:** Avoid `static` properties for request-specific data.

## 7. Future Features / Roadmap

Once the core coroutine support is stable, consider these advanced features to match Hyperf's performance:

### 1. Database Connection Pooling
Currently, Laravel opens a new connection (or reuses one) per request. In a high-concurrency coroutine environment, opening hundreds of TCP connections to MySQL/Redis can be expensive.

**Goal:** Maintain a pool of "hot" connections that coroutines can borrow and return.
- **Implementation:** Use `Swoole\Coroutine\Channel` to store `PDO` or `Redis` instances.
- **Benefit:** Zero handshake latency for DB queries.
- **Reference:** See how `smf/connection-pool` or Hyperf implements this.

### 2. Async HTTP Client
Replace Guzzle (blocking) with a Swoole-native HTTP client.
- **Goal:** Non-blocking external API calls without relying solely on hooks.
- **Implementation:** Wrap `Swoole\Coroutine\Http\Client`.

### 3. Coroutine-Aware Cache
Ensure the local Octane cache (Swoole Table) uses coroutine-safe keys (Cid) to prevent the "null key" errors we saw earlier.

## Summary
To fix the "Blocking I/O" issue with Swoole:
1.  **Fork Octane.**
2.  **Implement Client Pooling** to give each coroutine its own Laravel Sandbox.
3.  **Update `swoole-server`** to use `Coroutine::getContext()` and the Client Pool.
4.  **Disable/Update Watchdog** to respect coroutine IDs.
