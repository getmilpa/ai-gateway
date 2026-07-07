# Security Policy

## Supported Versions

Milpa AI Gateway is pre-1.0. Only the latest `0.x` release line receives security fixes.

## Known friction: debug logging of raw LLM responses

`LlmService` accepts an optional PSR-3 `LoggerInterface` and, when one is provided, logs
provider request/response details at `debug` level — including a slice of the **raw**
Anthropic response body and the raw tool-call arguments an LLM returned. These can carry
user-supplied prompt content, tool arguments, and — depending on what your tools return —
data that flowed back into the model. **Never wire a logger that writes to a durable,
widely-readable sink (a shared log aggregator, a paid file, an unredacted audit trail) at
`debug` level in production.** Use a `NullLogger` (or raise the minimum level above `debug`)
outside of local development, and treat any debug log stream as sensitive if you do enable it.

## Reporting a Vulnerability

Please report security vulnerabilities **privately** via GitHub Security Advisories
— the repository's **Security** tab → **Report a vulnerability** — rather than opening
a public issue or pull request.

We aim to acknowledge a report within 72 hours and to keep you informed as we work
on a fix. Once a fix is released, we will credit the reporter unless anonymity is
requested.

---

Milpa is developed and maintained by [TeamX Agency](https://teamx.agency).
