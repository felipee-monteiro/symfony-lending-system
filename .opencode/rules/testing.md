# Testing Rules

## Structure
- The test name describes the RULE, not the method:
  `test_lent_item_cannot_be_lent_again`, not `test_lendTo`.
- Use the Arrange-Act-Assert pattern, with comments separating the distinct phases.
- One concept per test. If the name requires an "and," it should be two separate tests.

## Invariants (What Matters Most)
- For every invariant, test BOTH sides:
  1. Happy path: a valid operation works AND modifies the state.
  2. Unhappy path: an invalid operation throws a `\DomainException` AND does NOT modify the state.
- For the unhappy path, verify atomicity: after the exception is thrown, the state must remain unchanged. Use `try/catch` combined with `self::fail()` when you need to verify the state; use `expectException` only when the state is irrelevant.

## What NEVER to Do
- Do not mock Value Objects. Instantiate them using `new` or a named constructor.
- Do not test getters in isolation. If a test merely calls a getter and performs a comparison, delete it.
- Do not use `assertNotNull` as the sole assertion.
- Do not use `assertEquals` for Value Objects or Enums; use `assertSame` instead.
- Do not use `KernelTestCase` for domain tests. Pure domain logic means ZERO kernel and ZERO database.
- Do not test internal implementation details (such as counting elements in an internal collection) when the public behavior is already being verified.

## Tiers
- Domain (Value Objects, Aggregates, Domain Services) → pure unit tests, no kernel.
- Repository → integration tests with a real database (using `KernelTestCase`); never mock the `EntityManager`.
- HTTP Flow → functional tests (using `WebTestCase`); cover only the critical path, with one test per use case.

## Data Providers
- Use data providers when the same scenario applies to different inputs. - The provider name describes the variation: `provide_invalidIntervals`, rather than `dataProvider1`.