# `lsr/otel` research and implementation plan

Research date: 2026-09-01

## Decision

Create `lsr/otel` as an opinionated Laser framework integration over the official OpenTelemetry PHP SDK, with OTLP over HTTP as the default publishing path. Keep official OpenTelemetry provider interfaces visible to framework code; do not wrap the tracer, meter, or logger interfaces with LSR copies.

The package should earn its place in three areas the SDK does not own:

1. Nette DI construction and standard LSR configuration.
2. Correct request/job/command lifecycle and context cleanup for FPM, CLI, and long-lived RoadRunner workers.
3. Small adapters at existing LSR framework seams.

Exporter implementation, signal models, propagation formats, resource models, batching, and sampling remain owned by OpenTelemetry.

## Verified ecosystem state

The official PHP implementation reports traces, metrics, and logs as stable. The project is split into independently released Composer packages from the `open-telemetry/opentelemetry-php` repository. The current stable versions inspected for this plan are:

| Package | Current stable | Role | `lsr/otel` decision |
| --- | ---: | --- | --- |
| `open-telemetry/api` | `1.10.0` | Public tracer, meter, logger, context, and global provider interfaces | Direct dependency because framework adapters will import API symbols |
| `open-telemetry/context` | `1.5.0` | Immutable context and active scope storage | Transitive through API/SDK; use directly where scopes are managed |
| `open-telemetry/sem-conv` | `1.38.0` | Semantic convention constants | Transitive initially; make direct when LSR adapters import constants |
| `open-telemetry/sdk` | `1.15.0` | Providers, processors, readers, resources, sampling, lifecycle | Required official SDK dependency |
| `open-telemetry/exporter-otlp` | `1.4.0` | OTLP traces, metrics, and logs exporters | Direct dependency for the default OTLP/HTTP path |
| `open-telemetry/sdk-configuration` | `0.9.0` | Declarative SDK configuration | Optional suggestion; do not make experimental configuration mandatory |
| `open-telemetry/transport-grpc` | `1.2.0` | OTLP/gRPC transport | Optional suggestion; requires `ext-grpc` |
| `open-telemetry/opentelemetry-auto-psr3` | `0.3.0` | PSR-3 trace-context injection or log export hooks | Optional suggestion; requires `ext-opentelemetry` |

The skeleton uses `open-telemetry/sdk:^1.15`, `open-telemetry/api:^1.10`, `open-telemetry/exporter-otlp:^1.4`, and `guzzlehttp/guzzle:^7.9`. Guzzle supplies a concrete PSR-18 client and PSR-17 factories discoverable by the SDK. This is deliberately an opinionated OTLP/HTTP default. A future exporter-neutral package would instead move exporter and HTTP client packages to `suggest`, but that would not satisfy the initial requirement to publish all three signals without additional dependency selection.

Do not use the broad `open-telemetry/opentelemetry` metapackage. It installs more exporter choices than this package needs and makes the actual runtime dependencies less explicit.

Primary sources:

- [OpenTelemetry PHP language status and package guidance](https://opentelemetry.io/docs/languages/php/)
- [Official SDK package metadata](https://packagist.org/packages/open-telemetry/sdk)
- [Official OTLP exporter metadata](https://packagist.org/packages/open-telemetry/exporter-otlp)
- [Official PHP repository package split](https://github.com/open-telemetry/opentelemetry-php/blob/main/.gitsplit.yml)
- [Official PSR-3 instrumentation metadata](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr3)

## Signal behavior and SDK constraints

### Traces

The SDK supplies tracer providers, samplers, span processors, propagation, and OTLP export. Batch span processing defaults observed in SDK source are a 2,048-record queue, 5-second scheduled delay, 30-second export timeout, and 512-record batch. Full queues drop ended sampled spans. Export errors are converted to failed results and reported through SDK diagnostics rather than thrown through the instrumented application path.

Use batch processing in deployed applications and in-memory/simple processing in deterministic tests. Sampling configuration remains standard OTEL configuration; `lsr/otel` should not invent a second sampling model.

Sources:

- [SDK setup examples](https://opentelemetry.io/docs/languages/php/sdk/)
- [`BatchSpanProcessor` source](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Trace/SpanProcessor/BatchSpanProcessor.php)
- [Trace SDK specification: processors, force flush, shutdown](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/sdk.md)

### Metrics

The SDK supplies synchronous instruments, readers, views, aggregation, and OTLP metric export. Current source has an important PHP-specific limitation: the environment-created `ExportingReader` identifies itself as periodic but does not establish an independent timer. Collection occurs when `collect()`, `forceFlush()`, or shutdown drives the reader. The package must not promise periodic publication merely because `OTEL_METRIC_EXPORT_INTERVAL` exists.

Initial implementation should therefore define collection points explicitly:

- FPM: force-flush after the response, with a bounded timeout.
- RoadRunner: collect/flush according to an explicit request-count or elapsed-time policy, plus worker shutdown.
- CLI/jobs: force-flush before command/task completion.

Do not flush every instrument operation. That removes batching and adds exporter latency to hot paths.

Sources:

- [`MeterProviderFactory` source](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Metrics/MeterProviderFactory.php)
- [`ExportingReader` source](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Metrics/MetricReader/ExportingReader.php)
- [Metrics SDK specification](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/metrics/sdk.md)

### Logs

The SDK has a stable logger provider and log record processors. Batch log processing defaults observed in source are a 2,048-record queue, 1-second scheduled delay, 30-second export timeout, and 512-record batch.

The first integration choice should be the official PSR-3 auto-instrumentation:

- `inject` mode keeps the existing logger output and adds active trace/span correlation to context.
- `export` mode converts PSR-3 calls into OTEL log records.

That package requires `ext-opentelemetry`, so it remains optional. If deployments cannot install the extension, add one extension-free PSR-3 bridge in `lsr/otel` against the official logger provider. Do not put OTEL dependencies into `lsr/logging`, and never enable automatic export plus a manual bridge together.

SDK-internal diagnostics must not be sent through a PSR-3 path that recursively generates new OTEL records. Configure the SDK diagnostic destination independently.

Sources:

- [OpenTelemetry PHP SDK logging configuration](https://opentelemetry.io/docs/languages/php/sdk/)
- [`BatchLogRecordProcessor` source](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/Logs/Processor/BatchLogRecordProcessor.php)
- [Logs SDK specification](https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/logs/sdk.md)
- [Official PSR-3 instrumentation metadata](https://packagist.org/packages/open-telemetry/opentelemetry-auto-psr3)

## Lifecycle, batching, and failure semantics

`SdkBuilder::setAutoShutdown(true)` registers provider shutdown callbacks for process shutdown. That is sufficient only for ordinary short-lived PHP processes. It is not a request boundary for a RoadRunner worker.

The package needs one deep lifecycle module that owns the differing provider calls and guarantees that all three signals are attempted even when one returns `false`. Its interface should remain limited to bounded `forceFlush()` and idempotent `shutdown()` operations. Framework adapters continue to request official tracer, meter, logger, and propagator interfaces directly.

Required invariants:

- Every activated context scope detaches in `finally`.
- Every started span ends in `finally`.
- A failed exporter never changes the application's response, command result, job acknowledgement decision, or DB/cache behavior.
- Force-flush attempts every provider; boolean failure is observable through diagnostics but not thrown into application control flow.
- Shutdown runs once per PHP process, not once per RoadRunner request.
- RoadRunner request/task completion ends and detaches context; periodic force-flush is distinct from provider shutdown.
- FPM may export after `fastcgi_finish_request()` so exporter delay does not extend client-visible response time.

The official OTLP HTTP transport defaults observed in source are a 10-second timeout and up to three retries. These are too large for a synchronous per-request flush. Deployment configuration must use bounded values appropriate to the Collector topology, and should send to a local Collector agent when possible.

Sources:

- [`SdkBuilder` lifecycle source](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/SDK/SdkBuilder.php)
- [PHP exporter guidance, including FastCGI](https://opentelemetry.io/docs/languages/php/exporters/)
- [`OtlpHttpTransportFactory` source](https://github.com/open-telemetry/opentelemetry-php/blob/main/src/Contrib/Otlp/OtlpHttpTransportFactory.php)

No official PHP source inspected defines RoadRunner-specific provider or fork lifecycle. RoadRunner behavior below is therefore an LSR integration design, not an upstream guarantee.

## Context propagation and resources

Use the standard W3C `traceparent`/`tracestate` and baggage propagators selected through `OTEL_PROPAGATORS`. Do not create LSR headers. Extract before creating the server/consumer span; inject when producing outbound HTTP requests or RoadRunner jobs.

OpenTelemetry context is immutable. Activating a context returns a scope; scope detachment is mandatory. Long-lived workers must begin each request/task from an extracted or root context and must prove that the next iteration cannot see the previous iteration's span. New PHP Fibers do not automatically inherit an arbitrary active context unless the selected storage/integration explicitly attaches it.

Resource configuration should use standard keys and precedence:

- `service.name`: required per deployed app/worker type, normally through `OTEL_SERVICE_NAME`.
- `service.version`: deployed application/package version.
- `deployment.environment.name`: production/staging/development.
- `service.instance.id`: process/worker identity where meaningful; do not generate a new ID per request.
- Additional attributes through `OTEL_RESOURCE_ATTRIBUTES`.

The official PHP service-instance detector deliberately avoids a default UUID in shared-nothing FPM/Apache environments. `lsr/otel` should not override that with per-request random values.

Sources:

- [PHP context documentation](https://opentelemetry.io/docs/languages/php/context/)
- [PHP propagation documentation](https://opentelemetry.io/docs/languages/php/propagation/)
- [PHP resource documentation](https://opentelemetry.io/docs/languages/php/resources/)
- [General SDK configuration](https://opentelemetry.io/docs/languages/sdk-configuration/general/)

## Framework integration matrix

| Owning package | Existing seam | Useful telemetry | Required change |
| --- | --- | --- | --- |
| `lsr/core` | `FpmHandler::run()` / `finishRequest()` and configured `AsyncHandlerInterface` handlers | Root HTTP server span, response status, exception, post-response flush | Add an OTEL request adapter around request handling; register a bounded post-response flush handler. Ensure cleanup still runs if response sending fails. |
| `lsr/core` | `App::run()`, route resolution, `RouteHandler` | Rename/enrich root span with matched route/controller after routing | Add attributes to the active span; do not create a second server span. Change `App::getLogger()` to resolve DI rather than construct `Logger` privately. |
| `lsr/request` | PSR request decorator and immutable attributes | Extract inbound W3C context; attach request metadata without coupling request DTOs to OTEL | Keep propagator work in the runtime adapter; avoid telemetry properties on request domain objects. |
| `lsr/routing` | Router match and route metadata | `http.route`, controller/action, not-found/method-not-allowed outcome | Enrich the existing server span after a route matches. Route templates, never raw high-cardinality paths, identify operations. |
| `lsr/roadrunner` | `HttpWorker::run()` request loop | Server span per request, status/error, request duration, context isolation | Extract/start/activate before dispatch; end/detach in `finally`; exercise two sequential requests; flush by policy, never shutdown per request. |
| `lsr/roadrunner` | `TaskProducer` and job options/headers | Producer span and W3C propagation | Inject context into RoadRunner task headers, not serialized command payloads. |
| `lsr/roadrunner` | `JobsWorker::handleTask()` | Consumer span, queue/task identity, ack/nack result, processing duration | Extract headers before processing; end/detach for success, exception, ack, and nack paths. Flush before worker/task completion according to policy. |
| `lsr/cqrs` | `CommandBus::dispatch()` and handler resolution | Command span, command/handler class, resolution/handler errors | Start before resolution and end after handler in `finally`. Async context remains transport-owned; do not add OTEL fields to `CommandInterface`. |
| `lsr/db` | Dibi `Connection::$onEvent` events after connect/query/transaction | Client spans/duration, operation type, errors, affected row count | Add a first-party Dibi event listener alongside logging. Avoid raw SQL and bound values by default. Move Dibi-specific logging translation out of `lsr/logging`. |
| `lsr/cache` | `Cache::load()` / `bulkLoad()` and generator fallback | Hit/miss/load counters, operation duration, generator failure | Instrument high-level cache operations to avoid duplicate Redis spans. Use per-operation/per-request instruments; current static cumulative counters are unsafe labels for RR request metrics. |
| `lsr/console` | Symfony Console `COMMAND`, `ERROR`, `TERMINATE` events; `setAutoExit(false)` | Command span, command name, exit code, exception | Register an event dispatcher/listeners or wrap `Application::run()`. Explicitly flush because auto-exit is disabled. |
| `lsr/logging` | PSR-3 `Logger::log()` | Trace correlation and OTEL log records | Finish PSR-3-compatible logger refactor. Prefer official optional PSR-3 instrumentation; keep SDK dependency in `lsr/otel`. |

### Duplicate-instrumentation rule

Use first-party LSR hooks when they add framework semantics unavailable to a vendor hook. Use official/vendor auto-instrumentation for generic Guzzle/PSR-18 calls, Redis commands, and supported libraries only when it does not duplicate an LSR span. Document the enabled instrumentation set and support standard disable controls.

### Attribute and cardinality rules

- Route templates, command classes, handler classes, cache operation names, queue names, and DB operation types are bounded enough for span attributes.
- Player IDs, game IDs, cache keys, raw URL paths, raw SQL, serialized commands, and exception messages must not become metric labels.
- SQL statements, HTTP headers, request bodies, baggage, and logger context may contain credentials or personal data; collection is opt-in and redacted.
- Metrics use low-cardinality dimensions. Detailed identifiers belong in traces/logs only when policy permits.

## Logger package update

The logger refactor is separately specified in [`../lsr-logger/LOGGER_UPDATE_PLAN.md`](../lsr-logger/LOGGER_UPDATE_PLAN.md).

Key decisions:

- Complete the disconnected formatter/storage work behind the existing API in a compatible `lsr/logging:0.3.x` release.
- Preserve the legacy constructor, `exception()`, `logDb()`, default filenames, default text output, and exported formatter/storage signatures throughout `0.3.x`.
- Keep `lsr/logging` free of SDK dependencies.
- Repair PHPUnit discovery before implementation; the current PHPUnit 12 configuration executes zero tests while PHPStan passes.
- Fix daily rollover, rotation, context mutation, and serialization fallback without forcing consumer migration.
- Add DI and narrow logger interfaces incrementally; migrate direct construction one application at a time.
- Keep Dibi translation operational while ownership moves to `lsr/db`.
- Treat a clean `0.4` removal release as optional follow-up after known consumers have migrated, not as an OTEL prerequisite.

## MVP

### Included

1. Composer package, namespace, validation configuration, and the official SDK/API/OTLP HTTP dependency set.
2. Nette DI extension that creates resource, tracer provider, meter provider, logger provider, standard propagator, and lifecycle module from standard OTEL configuration.
3. No-op-safe behavior when telemetry is disabled.
4. Explicit lifecycle integration for FPM, RoadRunner HTTP/jobs, and CLI.
5. Root HTTP and job spans with strict scope cleanup.
6. Standard propagation for inbound HTTP and RoadRunner jobs.
7. A minimal instrument registry/convention so each LSR package requests official tracers/meters with stable instrumentation names and versions.
8. In-memory signal tests and one local Collector smoke test proving traces, metrics, and logs leave the process.
9. Documentation of required service/resource configuration and exporter failure behavior.

### Non-goals

- Reimplementing SDK providers, exporters, processors, readers, samplers, propagation, or semantic-convention constants.
- A second configuration vocabulary that mirrors `OTEL_*` variables.
- Automatically instrumenting every method in every framework package.
- Capturing raw SQL, request/response bodies, arbitrary cache keys, or all logger context by default.
- Promising periodic metric export without an explicitly exercised collection mechanism.
- Requiring `ext-opentelemetry` for manual instrumentation.
- Coupling LaserArenaControl and LaserLiga deployment timing.

## Phased implementation

### Phase 1: runtime and configuration

- Implement the DI extension and lifecycle module over official providers.
- Define precedence: explicit Nette configuration overrides standard environment values only where a framework-specific value is necessary; otherwise the SDK remains authoritative.
- Support disabled/no-op operation.
- Add resource/service configuration and OTLP HTTP exporter construction.
- Test all three providers with official in-memory exporters.

### Phase 2: process lifecycle and propagation

- Integrate FPM server spans and post-response bounded flush.
- Integrate RoadRunner HTTP request spans, context detach, and periodic flush policy.
- Inject/extract RoadRunner job headers and add producer/consumer spans.
- Integrate Symfony console lifecycle and explicit command completion flush.
- Prove isolation with two sequential RR requests and two sequential jobs.

### Phase 3: framework semantics

- Add route/controller enrichment without duplicate server spans.
- Add CQRS dispatch spans.
- Add Dibi event spans and safe DB attributes.
- Add cache hit/miss/load metrics and high-level spans.
- Add standard low-cardinality framework/runtime metrics.

### Phase 4: logs

- Publish and validate the compatible `lsr/logging:0.3.x` completion first; existing consumers must continue working unchanged.
- Validate official PSR-3 `inject` and `export` modes against that compatible logger in a deployment with `ext-opentelemetry`.
- Progressively move framework and application consumers to DI and narrow logger interfaces without blocking OTEL adoption.
- Add an extension-free bridge only if a real deployment cannot use the official instrumentation.
- Test legacy file-output stability, exception mapping, trace correlation, recursion prevention, and exporter failure.
- Defer any `lsr/logging:0.4` removals until the consumer inventory is empty.

### Phase 5: consumer and publish validation

- Test through a Composer path repository with symlinking in one app.
- Validate FPM/HTTP or RoadRunner behavior with a local Collector.
- Commit/release/tag `lsr/otel` separately, add its future GitHub VCS repository to Satis metadata, and publish only after explicit approval.
- Remove the path repository, install from `https://packages.laserliga.cz`, and repeat the smoke scenario.
- Adopt in the second app independently after its constraints and process model are checked.

## Validation strategy

### Package tests

Use official in-memory exporters/readers to assert observable signal data rather than implementation calls:

- Trace: parent/child relation, route naming, status/error, exception event, propagated parent.
- Metrics: counter/histogram values, attributes, collection/force-flush behavior, no request-identity labels.
- Logs: severity/body/attributes, exception mapping, active trace/span correlation.
- Lifecycle: every provider gets force-flush/shutdown even if another fails; shutdown idempotency.
- Failure: closed/full processor, failed exporter, serialization error, cancellation/timeout.

### Framework tests

- FPM success, routing failure, controller exception, response-send failure, post-response flush.
- Two sequential RoadRunner requests with different trace parents and a no-parent request.
- RoadRunner producer/consumer propagation plus ack, nack, deserialization error, missing dispatcher, and handler exception.
- Dibi success/error/transaction events without raw SQL attributes.
- Cache hit, miss, generator error, bulk load, and Redis adapter with no duplicate span.
- CQRS missing handler, handler success/error, and async transport propagation.
- Console success/error and exit code with `setAutoExit(false)`.

### Runtime smoke test

Run an actual app process against a local OpenTelemetry Collector and an inspectable backend or debug exporter. Exercise one HTTP request, one DB/cache path, one command/job, and one structured log. Confirm all three signals share the expected resource and trace correlation, then stop the Collector to prove application behavior remains intact.

## Main risks

| Risk | Mitigation |
| --- | --- |
| Context leaks between RoadRunner iterations | Root/extracted context per iteration; `finally` detach; sequential-isolation tests |
| Export latency on request path | Batch; local Collector; post-response FPM flush; bounded RR/CLI flush policy |
| Queue overflow silently drops data | Monitor SDK diagnostics/internal metrics; size from measured traffic; never force-flush every record |
| Metrics are not actually periodic | Explicit collection points and runtime smoke test; do not rely on variable names alone |
| Duplicate spans/logs | One documented owner per seam; disable overlapping auto-instrumentation; never enable two log bridges |
| High-cardinality or sensitive attributes | Attribute allowlists, route templates, no raw SQL/cache keys/bodies by default |
| Logger recursion | Separate SDK diagnostic destination; test PSR-3 export failure path |
| Static framework state survives RR requests | Per-request OTEL state; reset/detach in worker `finally`; do not derive deltas from cumulative static counters without snapshots |
| Separate apps receive incompatible package updates | `0.x` constraints and lock files updated per app; path-repository validation before publication |
| gRPC/native extension deployment cost | OTLP/HTTP default; gRPC remains optional |

## Packaging and publication follow-up

The skeleton is an initialized independent Git repository at `Libraries/lsr-packages/lsr-otel` with package name `lsr/otel` and version `0.1.0`. It is not published and has no application consumer yet.

Before publication:

1. Create the remote VCS repository and set its origin.
2. Implement and validate the MVP; do not publish an empty runtime package as feature-complete.
3. Add the remote VCS URL to `Libraries/lsr-packages/satis.json` only after the repository exists.
4. Use the shared package release workflow: implementation commit, separate `:bookmark:` version commit/tag, push, explicit Satis publication approval, and published-package consumer verification.
