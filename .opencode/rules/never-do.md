## What NEVER to do

- Instantiating `new \DateTimeImmutable()` within the domain without injecting a `Clock` (exception: V1, see `DIVIDA.md`).
- Loading an Aggregate to generate a report — use a DBAL query and a DTO instead.
- Placing business logic inside a Controller or a Handler.