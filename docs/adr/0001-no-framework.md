# ADR 0001: No framework

**Status:** accepted

**Context:** PluginXchange is a small JSON API (about 12 endpoints). The goal is to show the fundamentals: routing, middleware, PDO/SQL, transactions.

**Decision:** Plain PHP 8.3 with Composer autoloading. Hand-written router and PDO repositories. No Laravel/Symfony.

**Consequences:** More boilerplate (router, error handling) but every layer is visible and explainable. Less built-in security and tooling, so we keep prepared statements everywhere and add lint/static analysis in CI.
