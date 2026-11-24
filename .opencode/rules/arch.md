## Architecture

- Pure domain in `src/Loan/Domain/` — **NO** imports from Doctrine, Symfony, or any other framework.
- Layers: Domain (pure) → Application (handlers) → Infrastructure (Doctrine, HTTP).
- Aggregates must protect their invariants; public setters are prohibited.
- Value Objects (VOs) must have a `private` constructor and use named constructors (e.g., `ofDays`, `of...`).