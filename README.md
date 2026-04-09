# Laravel Octane with Swoole Coroutine Support

⚡ **High-performance Laravel** with true coroutine support for massive concurrency

[![Packagist Version](https://img.shields.io/packagist/v/modelslab/octane-coroutine.svg)](https://packagist.org/packages/modelslab/octane-coroutine)
[![Packagist Downloads](https://img.shields.io/packagist/dt/modelslab/octane-coroutine.svg)](https://packagist.org/packages/modelslab/octane-coroutine)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/php-%5E8.1-777BB4.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-%5E10%7C%5E11%7C%5E12-FF2D20.svg)](https://laravel.com)
[![Swoole](https://img.shields.io/badge/swoole-required-00ADD8.svg)](https://www.swoole.co.uk)

> Requires the latest Swoole with coroutine hooks enabled. Older versions are not supported.

## 🚀 What is this?

This is an **enhanced fork** of Laravel Octane that adds **true Swoole coroutine support**, enabling your Laravel application to handle thousands of concurrent requests efficiently through non-blocking I/O.

### Performance Highlights

- **360× faster** than standard Octane (2,773 req/s vs 7.71 req/s baseline)
- **87× per-worker efficiency** through coroutines
- Handle **20,000+ concurrent connections** on a single server
- **Production-tested** under extreme load

## ⚡ The Problem with Standard Octane

Standard Octane uses a "One Worker = One Request" model. When a request performs blocking I/O (database queries, API calls, file operations), the entire worker is blocked:

```
8 workers × 1 request per worker = 8 concurrent requests max
```

With 1-second blocking operations, this means only **~8 requests/second** throughput.

## 🎯 The Solution: Runtime Coroutine Hooks

This fork enables **Swoole's coroutine runtime hooks** (`SWOOLE_HOOK_ALL`), which automatically converts PHP's blocking functions into non-blocking, coroutine-safe versions:

```
32 workers × ~87 concurrent requests per worker = 2,784+ concurrent requests
```

With the same 1-second blocking operations, this achieves **2,773+ requests/second** — a **360× improvement**!

### What Gets Hooked?

- ✅ `sleep()` → Non-blocking coroutine sleep
- ✅ `file_get_contents()` → Non-blocking file I/O
- ✅ `curl_exec()` → Non-blocking HTTP requests
- ✅ MySQL/PostgreSQL → Non-blocking database queries
- ✅ Redis → Non-blocking cache operations
- ✅ File operations → Non-blocking reads/writes

## 📦 Installation

Install via Composer from [Packagist](https://packagist.org/packages/modelslab/octane-coroutine):

```bash
composer require modelslab/octane-coroutine
```

Then install Octane with Swoole:

```bash
php artisan octane:install swoole
```

### Specific Version

```bash
# Install latest stable
composer require modelslab/octane-coroutine:^0.8.6

# Install development version
composer require modelslab/octane-coroutine:dev-main
```

### Updating the Package

```bash
# Update to the latest version
composer update modelslab/octane-coroutine

# Clear caches after updating
php artisan config:clear
php artisan cache:clear
php artisan octane:reload
```

**Tip**: Pin your production deployments to specific versions:

```json
{
    "require": {
        "modelslab/octane-coroutine": "^0.8.6"
    }
}
```

## 🔧 Configuration

The package works out-of-the-box with sensible defaults. Coroutines are **enabled by default** with runtime hooks.

### Worker Configuration

Start with appropriate worker count:

```bash
# Development (auto-detect CPU cores)
php artisan octane:start --server=swoole

# Production (explicit worker count)
php artisan octane:start --server=swoole --workers=32
```

### Advanced Configuration

Edit `config/octane.php` if needed:

```php
'swoole' => [
    'options' => [
        'enable_coroutine' => true,  // Already enabled by default
        'worker_num' => 32,
        'max_request' => 500,
    ],
],
```

### Redis & Database Coroutine Safety

Coroutine mode relies on coroutine-safe IO drivers and connection handling.
Recommended defaults:

```env
# Redis
REDIS_CLIENT=phpredis

# Database (disable PDO persistent connections in coroutine mode)
DB_PERSISTENT=false

# Optional startup warning buffer for DB min_connections planning
OCTANE_POOL_DB_MAX_CONNECTIONS_BUFFER=10
```

Notes:
- **phpredis** is fastest. This fork rewrites Redis handling for coroutine safety so
  request-scoped Redis managers do not reuse process-shared persistent sockets.
- **Predis** is not included by default. If you prefer a PHP-only client, install it
  manually and disable persistence:
  - `composer require predis/predis`
  - `REDIS_CLIENT=predis`
  - `REDIS_PERSISTENT=false`
- **PDO persistent connections** can cause cross-coroutine contention; keep them off.
- Database connection pooling in `config/database.php` is separate from the HTTP runtime.
  The only `octane.swoole.pool` setting still used is the DB warning buffer above.

## 🏊 Understanding Workers and Coroutines

This section clarifies the key concepts that make this fork different from standard Octane.

### What are Workers?

**Workers** are OS-level processes spawned by Swoole. Each worker:
- Is a separate PHP process with its own memory space
- Can handle requests independently
- Is configured via `--workers=N` or `worker_num` in config

```
Standard Octane: 1 Worker = 1 Request at a time (blocking)
```

### What are Coroutines?

**Coroutines** are lightweight, cooperative "threads" managed by Swoole at the application level (not OS-level). When a coroutine encounters blocking I/O, it **yields** control to other coroutines instead of blocking the entire worker.

```
Traditional: Worker blocks → other requests wait
Coroutines:  Worker yields → other requests continue
```

### How They Work Together

```
┌─────────────────────────────────────────────────────────────┐
│                     SWOOLE SERVER                           │
├─────────────────────────────────────────────────────────────┤
│  Worker 0                      Worker 1                     │
│  ┌─────────────────────┐       ┌─────────────────────────┐  │
│  │ Shared Laravel      │       │ Shared Laravel          │  │
│  │ worker runtime      │       │ worker runtime          │  │
│  │                     │       │                         │  │
│  │ Coroutines:         │       │ Coroutines:             │  │
│  │ cid:1 → scope A     │       │ cid:1 → scope A         │  │
│  │ cid:2 → scope B     │       │ cid:2 → scope B         │  │
│  │ cid:3 → scope C     │       │ cid:3 → scope C         │  │
│  │ ...                 │       │ ...                     │  │
│  └─────────────────────┘       └─────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### How Request Isolation Works

This runtime does **not** use an HTTP application pool anymore. Each Swoole worker
boots one shared Laravel worker runtime, and concurrent requests are isolated by
the coroutine-aware container proxy plus per-request scoped state for bindings
such as request, session, router, view, log, cache, and Redis managers.

The legacy `octane.swoole.pool` config namespace remains only for
`db_max_connections_buffer`, which is used by a startup safety warning.

## 🧪 Testing

Unit tests require a PHP build with the Swoole extension installed.

```bash
php83 vendor/bin/phpunit --testsuite Unit
```

### Coroutine Runtime Configuration

The package is pool-free for HTTP workers. The relevant Swoole config looks like:

```php
'swoole' => [
    'options' => [
        'worker_num' => 8,
    ],

    'pool' => [
        'db_max_connections_buffer' => env('OCTANE_POOL_DB_MAX_CONNECTIONS_BUFFER', 10),
    ],
],
```

`OCTANE_POOL_DB_MAX_CONNECTIONS_BUFFER` only affects the startup warning that
checks MySQL `max_connections` against your configured database pool minimums.

## ⚡ Performance Optimization

### CPU Usage and Tick Timers

**Following Hyperf/Swoole best practices**, this fork **disables tick timers by default** to prevent unnecessary CPU usage.

#### What are Tick Timers?

Octane can dispatch "tick" events to task workers every second. However:

- **Tick is disabled by default** (`'tick' => false` in `config/octane.php`)
- **Task workers are set to 0 by default** when tick is disabled
- This prevents **100% CPU usage** from idle task workers waking up every second

#### Why Disable Tick?

In earlier configurations, tick timers with `--task-workers=auto` would create one task worker per CPU core (e.g., 12 workers on a 12-core system). Even with no traffic:

```
12 task workers × tick every 1 second = constant CPU overhead
```

This causes high CPU usage even when the server is idle!

#### When to Enable Tick

Only enable tick if you have **listeners for `TickReceived` or `TickTerminated` events** that need to run periodically:

```php
// config/octane.php
'swoole' => [
    'tick' => true,  // Enable tick timers
],
```

Then start with **minimal task workers** (not auto):

```bash
# Good: Only 1-2 task workers for tick
php artisan octane:start --task-workers=1

# Bad: Creates CPU_COUNT task workers (excessive overhead)
php artisan octane:start --task-workers=auto
```

#### Task Worker Guidelines

| Scenario | Recommended `--task-workers` |
|----------|------------------------------|
| Tick disabled (default) | `0` (auto) |
| Tick enabled | `1` or `2` |
| Heavy async task dispatch | `2` to `4` |
| Never use | `auto` (causes CPU overhead) |


## 📊 Performance Benchmarks

Real-world load testing results with `wrk`:

### Baseline (No Coroutines)
```bash
wrk -t12 -c2000 -d30s http://localhost:8000/test
```
- **Workers**: 8
- **Result**: 7.71 req/s

### With Coroutines Enabled
```bash
wrk -t12 -c20000 -d60s http://localhost:8000/test
```
- **Workers**: 32
- **Result**: 2,773.34 req/s
- **Improvement**: **360×**

### Per-Worker Efficiency

| Configuration | Req/sec per worker | Concurrent requests per worker |
|---------------|-------------------|-------------------------------|
| Standard Octane | ~1 | 1 |
| With Coroutines | ~87 | ~87 |

Each worker can efficiently handle **~87 concurrent requests** thanks to coroutines!

## 🏗️ Architecture

### Runtime Hooks

Enabled automatically on worker start:

```php
// src/Swoole/Handlers/OnWorkerStart.php
\Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
```

This converts all blocking I/O to coroutine-safe operations without any code changes required.

### Worker Initialization

Workers log their initialization for monitoring:

```
🚀 Worker #0 starting initialization...
✅ Worker #0 (PID: 4958) initialized and ready!
```

### Graceful Degradation

If a worker isn't ready, requests receive `503` responses until initialization completes:

```json
{
  "error": "Service Unavailable",
  "message": "Worker not initialized yet",
  "worker_id": 5
}
```

## 🎯 When to Use This Fork

### ✅ Perfect For:

- Applications with **external API calls** (payment gateways, third-party services)
- **Database-heavy** applications with long queries
- **High-concurrency** requirements (1,000+ concurrent users)
- Applications performing **file I/O** (uploads, processing)
- Any app with **blocking operations** that can benefit from async

### ⚠️ Standard Octane is Fine For:

- Purely **CPU-bound** operations (image processing, calculations)
- **Ultra-fast** responses (<50ms average)
- **Low-concurrency** requirements (<100 concurrent users)

## 🔍 Monitoring

### Worker Logs

Check worker initialization in your logs:

```bash
tail -f storage/logs/swoole_http.log | grep "Worker"
```

### Performance Metrics

Monitor your application:

- **5xx rate**: Watch for upstream or worker errors under load
- **Memory usage**: ~50-200MB per worker depending on application
- **Worker count**: Scale based on CPU cores (typically 1-2× CPU count)
- **Worker restarts**: Unexpected churn usually means a fatal error or memory issue

## 🛠️ Production Recommendations

### Resource Planning

```
Memory needed ≈ workers × 100-200MB per worker
```

**Example**: 32 workers = 3.2-6.4GB RAM

### OS Tuning

For high concurrency (10,000+ connections):

```bash
# Increase file descriptor limits
ulimit -n 65536

# Add to /etc/security/limits.conf
* soft nofile 65536
* hard nofile 65536
```

### Swoole Configuration

For extreme load:

```php
// config/octane.php
'swoole' => [
    'options' => [
        'worker_num' => 64,
        'backlog' => 65536,
        'socket_buffer_size' => 2097152,
    ],
],
```

## 🐛 Debugging

Enable debug logging to track worker behavior:

```php
// Check worker initialization
tail -f storage/logs/swoole_http.log

// Monitor in real-time
php artisan octane:start --server=swoole --workers=32 | grep "Worker"
```

## ⚠️ Important Notes

- **Database connections**: Ensure `max_connections` can handle your concurrency
- **Memory**: Monitor usage and scale workers accordingly
- **Warmup**: Workers initialize automatically; allow 5-10 seconds before heavy load
- **State management**: Laravel's service container handles coroutine isolation automatically
- **Proxy timeouts**: If you're behind Nginx/ALB, set upstream read timeouts above your max request time

## 📈 Scaling Guide

### Small (Development)
- Workers: 4-8
- Handles: ~500 concurrent requests
- RAM: 2-4GB

### Medium (Production)
- Workers: 16-32
- Handles: ~2,000 concurrent requests
- RAM: 4-8GB

### Large (High-Traffic)
- Workers: 32-64
- Handles: ~5,000 concurrent requests
- RAM: 8-16GB

### XL (Enterprise)
- Workers: 64-128
- Handles: ~10,000+ concurrent requests
- RAM: 16-32GB

## 🎯 Recommended Configuration: 8-Core CPU for 10K req/sec

This section provides specific, tested recommendations for achieving **10,000 requests/second** on an 8-core CPU.

### Understanding the Math

```
For 10K req/sec with 100ms average response time:
- Concurrent requests needed: 10,000 × 0.1 = 1,000 concurrent
- With 8 workers, each needs: 1,000 ÷ 8 = 125 concurrent per worker
- There is no HTTP app pool cap; the real limits are memory, upstream capacity,
  and whether your request path actually yields on blocking I/O.
```

### Recommended Configuration

```php
// config/octane.php
'swoole' => [
    'options' => [
        'worker_num' => 8,              // Match CPU cores
        'max_request' => 10000,         // Restart worker after N requests (memory safety)
        'max_request_grace' => 1000,    // Grace period for graceful restart
        'backlog' => 8192,              // Connection queue size
        'socket_buffer_size' => 2097152, // 2MB socket buffer
        'buffer_output_size' => 2097152, // 2MB output buffer
    ],
],
```

### Start Command

```bash
php artisan octane:start \
    --server=swoole \
    --workers=8 \
    --task-workers=0 \
    --max-requests=10000 \
    --port=8000
```

### Resource Requirements

| Resource | Minimum | Recommended |
|----------|---------|-------------|
| CPU | 8 cores | 8+ cores |
| RAM | 8GB | 16GB |
| File Descriptors | 65536 | 100000+ |
| Network | 1Gbps | 10Gbps |

### Memory Calculation

```
Memory ≈ worker count × base worker footprint + in-flight request state

Measure this under real load in your application. Pool-free coroutine mode is
dramatically lighter than pre-booting many application instances per worker, but
the exact number still depends on your middleware, services, and payload sizes.
```

### Database Connection Pooling

Database pooling is separate from the HTTP runtime. Size it from worker count and
your configured per-connection pool minimums and maximums.

```php
// config/database.php
'mysql' => [
    'driver' => 'mysql',
    // ... other config
    'pool' => [
        'min_connections' => 1,
        'max_connections' => 50,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
    ],
],
```

For example, `8 workers × min_connections=1` means at least `8` DB connections
before real traffic. `OCTANE_POOL_DB_MAX_CONNECTIONS_BUFFER` only adjusts the
startup warning threshold for this planning.

Or configure MySQL server:
```sql
SET GLOBAL max_connections = 500;
SET GLOBAL wait_timeout = 28800;
```

### OS Tuning for 10K req/sec

```bash
# /etc/sysctl.conf
net.core.somaxconn = 65535
net.core.netdev_max_backlog = 65535
net.ipv4.tcp_max_syn_backlog = 65535
net.ipv4.ip_local_port_range = 1024 65535
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_fin_timeout = 15
net.core.rmem_max = 16777216
net.core.wmem_max = 16777216

# Apply changes
sysctl -p
```

```bash
# /etc/security/limits.conf
* soft nofile 100000
* hard nofile 100000
* soft nproc 65535
* hard nproc 65535

# Apply (requires re-login)
ulimit -n 100000
```

### Benchmark Expectations

With the above configuration on 8-core CPU:

| Scenario | Expected req/sec |
|----------|------------------|
| Simple JSON response | 15,000-20,000 |
| Database SELECT (cached) | 8,000-12,000 |
| Database SELECT (no cache) | 3,000-6,000 |
| External API call (100ms) | 8,000-10,000 |
| Complex business logic | 5,000-8,000 |

### Tuning Tips

1. **Start Conservative**: Begin with a modest worker count and measure under load
2. **Monitor Actively**: Watch memory, 5xx rates, worker restarts, and upstream saturation
3. **Warm Up**: Allow 30-60 seconds for workers to warm up before heavy traffic
4. **Use Redis**: Offload sessions and cache to Redis for better concurrency
5. **Size Database Pools Separately**: Prevent DB connection exhaustion independently of HTTP concurrency

## 📚 Resources

- [Laravel Octane Documentation](https://laravel.com/docs/octane)
- [Swoole Documentation](https://www.swoole.co.uk/docs)
- [Coroutine Programming Guide](https://www.swoole.co.uk/docs/modules/swoole-coroutine)

## 🤝 Contributing

Contributions are welcome! Please read the [contribution guide](.github/CONTRIBUTING.md).

## 🔒 Security

Please review [our security policy](https://github.com/laravel/octane/security/policy) to report vulnerabilities.

## 📄 License

This fork maintains the original MIT license. See [LICENSE.md](LICENSE.md).

---

**Built with ❤️ by [ModelsLab](https://github.com/ModelsLab)**

**Original Laravel Octane** by Taylor Otwell and the Laravel team
