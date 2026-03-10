# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-03-10

### Added

- Initial release
- Laravel Queue provider with support for all queue drivers (Redis, Database, SQS, Beanstalkd, etc.)
- Symfony Messenger provider with support for all transports (AMQP, Redis, Doctrine, InMemory, etc.)
- Multi-provider support for monitoring multiple queue systems simultaneously
- Event batching with configurable batch size and buffer limits
- HTTP transport with exponential backoff retry logic
- Sensitive data redaction with configurable key lists
- In-job logging for step-by-step visibility
- Deployment tracking
- Laravel auto-discovery service provider with zero-config setup
- Global singleton facade via `Jobviz` helper class
