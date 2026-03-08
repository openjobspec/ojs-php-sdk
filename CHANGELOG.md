# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-02-28

### Added
- Durable execution support with replay log, checkpoints, and side-effects
- Batch enqueue with validation
- Configurable retry backoff (exponential, linear, fixed)
- Configurable request timeout
- Encryption middleware for client-side payload encryption
- SSE event subscription via `Client::subscribe()`
- Schema validation for job arguments
- Testing utilities with fake mode
- Dead letter queue management
- OpenTelemetry tracing middleware
- Queue management (pause, resume, stats)

### Fixed
- Empty response body handling for 204 responses
- JSON encoding of nested array arguments

## [0.1.0] - 2026-01-15

### Added
- Initial release
- Client with enqueue, batch, cancel, getJob operations
- Worker with handler registration, middleware chain, graceful shutdown
- Workflow support (chain, group, batch)
- Retry policies (exponential, linear, constant backoff)
- Unique job policies
- Cron scheduling
