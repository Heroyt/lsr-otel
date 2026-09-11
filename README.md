# LSR OpenTelemetry

`lsr/otel` integrates OpenTelemetry providers, tracing, metrics and structured log storage with Nette DI and optional LSR lifecycle hooks. It can instrument supported framework services without making all framework packages mandatory dependencies.

## Requirements

- PHP `>=8.4` and Nette DI `^3.2`.
- OpenTelemetry API `^1.10`, SDK `^1.15` and OTLP exporter `^1.4`.
- Guzzle `^7.9` for the bundled HTTP transport stack.
- No PHP extensions are mandatory in this package's manifest. `ext-opentelemetry` is suggested for hook-based automatic instrumentation; the explicit LSR lifecycle bridges do not require it.
- Install the optional LSR packages for the integrations you use. Console instrumentation additionally needs Symfony Console and Symfony EventDispatcher. PSR-3 auto-instrumentation, declarative SDK configuration and gRPC transport are separate suggested packages listed in [composer.json](composer.json).
- Configure exporters and reachable telemetry backends in the consuming environment; this library does not deploy a collector or storage backend.

## Installation

```shell
composer require lsr/otel
```

## Configuration

Register the extension in the application's Nette DI configuration:

```neon
extensions:
    otel: Lsr\Otel\DI\OtelExtension

otel:
    enabled: true
    registerGlobal: true
    autoShutdown: true
    applicationInstrumentation:
        name: example/application
        version: '1.0.0'
```

`applicationInstrumentation.name` must be a Composer-style package name; replace `example/application` with the application's identity. Setting it registers autowired `Lsr\Otel\Tracing` and `Lsr\Otel\Metrics` helpers. Without it, use `Lsr\Otel\InstrumentationRegistry` to request explicitly named instrumentation scopes.

The extension creates trace, metric and log providers, a propagator and an SDK. [ProviderFactory](src/ProviderFactory.php) delegates resource and exporter configuration to the OpenTelemetry SDK factories, rather than defining separate endpoint or credential keys in NEON. Configure the installed SDK's environment settings before initializing the container. `enabled: false` selects no-op providers and skips framework bridges; `registerGlobal: false` prevents this extension from registering its SDK globally.

## Manual instrumentation

Inject `InstrumentationRegistry` into an application service to use the same providers with a named scope:

```php
use Lsr\Otel\InstrumentationRegistry;
use OpenTelemetry\API\Trace\SpanInterface;

function checksum(InstrumentationRegistry $registry, string $contents): string
{
    $tracing = $registry->tracing('example/application');
    $metrics = $registry->metrics('example/application');

    $digest = $tracing->trace(
        'document.checksum',
        static fn(SpanInterface $span): string => hash('sha256', $contents),
    );
    $metrics->counter('application.documents.checksummed')->add();

    return $digest;
}
```

`Tracing::trace()` returns the callback result, records thrown exceptions and rethrows them, then ends the span. For manually scoped work, `start()` returns an `ActiveSpan`; call `fail()` on failure and always `end()` in a `finally` block.

`Metrics::counter()` and `histogram()` cache instruments by name. Reusing a name with a different instrument type, unit or description throws `LogicException`; keep definitions consistent within each instrumentation scope. The registry also exposes the underlying tracer, meter and logger APIs.

## Log storage

Install `lsr/logging` with the record-aware stack-storage API to use [`OtelStorage`](src/Logging/OtelStorage.php). The integration remains optional; non-logging telemetry also works without `lsr/logging`, or with an older logging release when automatic wiring is off.

Explicit storage is the default. No `ext-opentelemetry`, PSR-3 interception, or global SDK registration is needed:

```php
use Lsr\Logging\Logger;
use Lsr\Logging\Storage\StackStorage;
use Lsr\Logging\Storage\DailyLogStorage;
use Lsr\Logging\Formatter\JsonLogFormatter;
use Lsr\Otel\InstrumentationRegistry;
use Lsr\Otel\Logging\OtelStorage;

function applicationLogger(InstrumentationRegistry $registry, string $directory): Logger {
    return new Logger($directory, 'application', new StackStorage([
        new DailyLogStorage($directory, 'application', new JsonLogFormatter()),
        new OtelStorage($registry->logger('example/application')),
    ]));
}
```

With compatible logging installed, `OtelExtension` registers non-autowired `@otel.logging.logger` and `@otel.logging.storage` services even when automatic wiring is off. The former uses the `lsr/logging` instrumentation scope; the latter can be referenced directly or used in a stack configured through `LoggerExtension`:

```neon
extensions:
    logging: Lsr\Logging\DI\LoggerExtension
    otel: Lsr\Otel\DI\OtelExtension

logging:
    default: @logging.loggers.app
    storages:
        local: Lsr\Logging\Storage\SimpleFileStorage(
            '%constants.appDir%logs/application.log',
            @loggerJsonFormatter
        )
        telemetry: Lsr\Logging\Storage\FilteredStorage(
            @otel.logging.storage,
            warning,
            Lsr\Logging\Filter\ContextBlacklistFilter([password, token, authorization])
        )
        stack: Lsr\Logging\Storage\StackStorage([@logging.storages.local, @logging.storages.telemetry])
    loggers:
        app:
            storage: @logging.storages.stack
        imports:
            name: result-import
            storage: @logging.storages.stack
```

Implementations use FQNs/native Nette constructor statements or `@service` references, not driver strings. Severity names such as `warning` are standard PSR-3 values. If an application already includes logging's `services.neon`, do not register `LoggerExtension` again; that file now registers it.

### Exported records and filtering

- The body preserves the supplied message; severity is mapped to OTEL severity numbers and text. Records automatically capture the active span's trace/span context.
- The logger's existing `$fileName` becomes the `lsr.logger.name` record attribute, so loggers sharing one storage remain distinguishable. Context cannot overwrite this attribute. Anonymous direct `OtelStorage::store()` calls have no logger-name attribute.
- Context is allowed by default and normalized through the logging package. Scalars and homogeneous scalar lists remain native attributes; nested objects/arrays and mixed lists become JSON strings; top-level null attributes are omitted. Resource/service identity stays on the existing provider resource.
- PSR-3 `['exception' => $throwable]` context produces `exception.type`, `exception.message`, and `exception.stacktrace`. These are derived from filtered context, so redacted exception details are not reintroduced. The legacy `Logger::exception()` helper remains two text records; pass exception context explicitly when semantic exception attributes are needed.
- Wrap a destination in `FilteredStorage` to set a minimum severity, transform context or drop records. Its filter may be an invokable `LogFilterInterface`, a callable in PHP, or a DI filter service. `ContextBlacklistFilter` removes exact, case-sensitive keys recursively and does not change sibling file output. It cannot remove secrets embedded in message text or other strings.

### Opt-in automatic wiring

```neon
otel:
    integrations:
        logging:
            autoWire: true
            level: warning
            filter: @exportFilter

services:
    exportFilter: Lsr\Logging\Filter\ContextBlacklistFilter([password, token])
```

`autoWire` defaults to `false`, `level` to `DEBUG` and `filter` to `null`. Automatic wiring adds an OTEL destination to container-managed `Lsr\Logging\Logger` services, including manually declared services and factory-produced services whose declared type is `Logger`. Their original destinations are retained. It does not intercept arbitrary `new Logger(...)` calls or unrelated PSR-3 implementations.

Before adding a destination, the integration recursively examines `CompositeStorageInterface` children. An existing `OtelStorage` anywhere in the tree takes precedence, including a filtered or nested explicit destination. Automatic level/filter settings do not override it or cause duplicate exports. Custom storage decorators must expose their children through `CompositeStorageInterface` to participate in this discovery.

`otel.enabled: false` prevents automatic attachment and makes explicit storage use no-op providers. Enabling automatic wiring with incompatible/missing logging support produces a configuration error.

Do not combine explicit/automatically attached storage with PSR-3 auto-instrumentation in **export** mode: that is a separate export path and produces duplicate records. Injection-only PSR-3 instrumentation does not add a second export.

Storage uses the existing provider's processing, flush and shutdown lifecycle below; it does not flush on every write. A stack attempts all destinations before reporting synchronous failures (`ignoreExceptions: true` opts out). Deferred exporter failures are governed by the OpenTelemetry SDK, not stack exception handling.

## Framework bridges

The [DI extension](src/DI/OtelExtension.php) conditionally registers bridges for Core HTTP/request operations, RoadRunner HTTP and jobs, Console, CQRS, cache, routing, scheduler, authentication, request mapping, Inertia, database and ORM operations. Compatible lifecycle interfaces and the relevant service definitions must be present; installing an older optional package without its hook API is not enough.

Cache traces and metrics use the `lsr/caching` instrumentation scope. Update collector or dashboard filters that match the former `lsr/cache` scope; metric names remain `lsr.cache.*`.

Tracing/metric integration groups expose `enabled`, `traces` and `metrics` switches; logging uses the separate options above. For example:

```neon
otel:
    integrations:
        database:
            includeRawSql: false
        orm:
            hydration: false
            modelMetrics: false
        roadrunner:
            flushEvery: 100
            flushInterval: 10.0
```

Raw SQL tracing, ORM hydration and model-specific metrics are disabled by default. Consider sensitive data and attribute cardinality before enabling additional detail or adding application attributes.

Automatic DI wiring attaches hooks to registered service definitions. Objects constructed directly or returned by another factory are not automatically covered by that wiring. In particular, the standard Inertia factory creates request-specific `Inertia` objects itself; applications using that path must attach an Inertia lifecycle hook to those instances if they want render instrumentation. See [src/Bridge](src/Bridge) for each bridge's concrete scope.

## Flush and shutdown

`Lsr\Otel\Lifecycle\TelemetryLifecycleInterface` exposes `forceFlush(): bool` and `shutdown(): bool`. The extension initializes lifecycle management when the compiled container is initialized; `autoShutdown` defaults to `true`.

The Core bridge adds an FPM flush handler, the RoadRunner bridge flushes periodically at worker iteration boundaries and once more when workers stop, and the Console bridge flushes on command termination. Provider shutdown is handled separately by the lifecycle shutdown registration. For other long-running execution models, call `forceFlush()` at appropriate boundaries and `shutdown()` when the process finishes. An iteration-based interval is not an independent background timer.

A RoadRunner-focused Grafana dashboard is included at [grafana/lsr-roadrunner-dashboard.json](grafana/lsr-roadrunner-dashboard.json); adapt its data sources to the deployed observability stack.

## Development

CI runs the checks below on PHP 8.4 and 8.5. From a package checkout:

```shell
composer install --prefer-dist --no-interaction --no-progress
composer cs
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit --no-coverage
```

`composer cs` checks coding style without changing files. Run `composer cs:fix` (or `composer cbf`) to apply PHP CS Fixer rules from [.php-cs-fixer.php](.php-cs-fixer.php).

The development dependencies include the optional LSR bridges, Nyholm PSR-7, Symfony Console/EventDispatcher and PSR-3 auto-instrumentation exercised by the suite; these remain optional for consumers. Tests use this checkout's Composer autoloader. No collector, Redis server, database server or RoadRunner binary is needed.

The log-storage tests require `lsr/logging` `^0.3.4`, which supplies the record-aware storage API. For unpublished logging changes, use a temporary Composer `path` repository pointing at its working tree with `options.symlink: true`, then update only `lsr/logging` and necessary dependencies. Remove the temporary repository before publishing and verify against the released package. The internal PSR-20 clock replacement does not require a breaking release or consumer migration.

CI installs `ext-opentelemetry` for the PSR-3 integration tests, plus the framework dependency extensions (Redis, PDO SQLite, gettext, fileinfo, SimpleXML, ZIP and sockets) and PHPUnit's DOM, mbstring, XML and XMLWriter extensions. The `composer test` script enables Xdebug coverage; the CI command explicitly disables coverage collection.

## AI coding assistance

See [LSR Skills](https://github.com/Heroyt/lsr-skills) for AI agent skills for working with the LSR framework.

## License

Licensed under the [MIT License](LICENSE).
