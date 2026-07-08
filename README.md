<p align="center">
  <a href="https://github.com/getmilpa">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-dark.svg">
      <img src="https://raw.githubusercontent.com/getmilpa/core/main/art/lockup/milpa-lockup-v-color-light.svg" alt="Milpa" width="300">
    </picture>
  </a>
</p>

# Milpa AI Gateway

> A **dual-provider LLM gateway** for the Milpa PHP framework — one client for OpenAI and
> Anthropic chat completions, translating each provider's tool-call wire format to and from a
> single shape, plus an **agentic tool-use loop** that drives a `milpa/tool-runtime`
> `ToolRegistry` (resolve → validate → authorize → execute → audit) until the model is done.

[![CI](https://github.com/getmilpa/ai-gateway/actions/workflows/ci.yml/badge.svg)](https://github.com/getmilpa/ai-gateway/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/milpa/ai-gateway.svg)](https://packagist.org/packages/milpa/ai-gateway)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.3-777bb4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-API%20reference-blue.svg)](https://getmilpa.github.io/ai-gateway/)

`milpa/ai-gateway` is the LLM tier of Milpa: the piece that turns a `milpa/tool-runtime`
`ToolRegistry` into something a model can actually drive. `LlmService` implements
`milpa/core`'s `LlmServiceInterface` seam against two concrete providers — OpenAI and
Anthropic — so callers write one message shape and one tool-call shape regardless of which
provider answers. `AgentOrchestrator` runs the loop every agent needs: ask the model, execute
whatever tools it asks for through the registry pipeline, feed the results back, repeat until
the model returns a final answer or a step budget runs out. **No product coupling, no
Telegram/HTTP-specific code** — those live in your host application.

## Install

```bash
composer require milpa/ai-gateway
```

## Quick example

Register a tool on a `ToolRegistry` (from `milpa/tool-runtime`), wrap it in `McpClientService`,
and hand both to `AgentOrchestrator` along with an `LlmService`:

```php
use Milpa\AiGateway\AgentOrchestrator;
use Milpa\AiGateway\LlmService;
use Milpa\AiGateway\McpClientService;
use Milpa\ToolRuntime\ToolRegistry;
use Psr\Log\NullLogger;

$registry = new ToolRegistry(new NullLogger());
$registry->register(
    'get_time',
    'Get the current time',
    [],
    fn () => ['time' => '12:00 PM'],
);

$mcpClient = new McpClientService($registry);
$llm = new LlmService(apiKey: getenv('OPENAI_API_KEY'), model: 'gpt-4o', provider: 'openai');
// LlmService talks HTTP through PSR-18 (Psr\Http\Client\ClientInterface), defaulting to a
// Guzzle client when none is injected — see "Bringing your own HTTP client" below.

$orchestrator = new AgentOrchestrator($llm, $mcpClient);

echo $orchestrator->run('What time is it?');
// -> asks the model, the model requests `get_time`, AgentOrchestrator executes it through
//    the registry, feeds the result back, and returns the model's final answer.
```

Swap `provider: 'anthropic'` and a Claude model name (or let a `claude` model name in
`$model` select it automatically) to point the same call at Anthropic instead — `LlmService`
translates the tool list, the message history, and the tool-call response to and from
Anthropic's shape internally, so `AgentOrchestrator` and `McpClientService` never see a
provider-specific format.

## The agent loop

`AgentOrchestrator::run()` alternates between two calls until the model is done or
`$maxSteps` (default 20) is reached:

1. **Ask** — `LlmService::generateResponse()` sends the running message history plus the
   registry's tool summaries (`McpClientService::getToolSummaries()`) to the provider and
   returns a single OpenAI-shaped assistant message.
2. **Act** — if that message carries `tool_calls`, each one is executed via
   `McpClientService::callTool()`, which runs it through the full `ToolRegistry` pipeline
   (validate → authorize → execute → audit) under whatever `ToolContext` was set with
   `setToolContext()`. The result — rendered through a `RendererRegistry` when one is
   configured, JSON otherwise — is fed back into the message history as a `tool` message, and
   the loop repeats.

If a tool result requires confirmation or is blocked by policy, the loop stops immediately and
returns that outcome instead of continuing — the caller (a chat handler, a CLI, a bot) is
responsible for the confirm/cancel round trip on the next user turn.

## Provider translation

`LlmService` speaks one shape to its callers — OpenAI's `messages` / `tool_calls` — and
translates both directions for Anthropic:

- **Outbound**: `system` messages become Anthropic's top-level `system` parameter; `tool`
  role messages become `user` messages carrying a `tool_result` content block; an assistant
  message with `tool_calls` becomes `tool_use` content blocks. Tool summaries are reshaped
  from `{name, description, inputSchema}` to Anthropic's `{name, description, input_schema}`,
  with an empty `properties` object substituted where a tool declares none (Anthropic requires
  a non-empty schema object, not an empty array).
- **Inbound**: Anthropic's `content: [{type: text, ...}, {type: tool_use, ...}]` array is
  flattened back into a single OpenAI-shaped assistant message (`content` + `tool_calls`), so
  `AgentOrchestrator` runs identical logic regardless of provider.

### Bringing your own HTTP client (PSR-18)

`LlmService`'s constructor accepts a PSR-18 `ClientInterface` (plus PSR-17 request/stream
factories) — inject your own for connection pooling, retry/circuit-breaker middleware, or
tests that assert on the outgoing request without touching the network:

```php
use Milpa\AiGateway\LlmService;

$llm = new LlmService(
    apiKey: getenv('ANTHROPIC_API_KEY'),
    model: 'claude-3-5-sonnet-20241022',
    provider: 'anthropic',
    logger: $logger,               // Psr\Log\LoggerInterface; optional
    httpClient: $yourPsr18Client,  // Psr\Http\Client\ClientInterface; omit for Guzzle
    requestFactory: $yourFactory,  // Psr\Http\Message\RequestFactoryInterface; optional
    streamFactory: $yourFactory,   // Psr\Http\Message\StreamFactoryInterface; optional
);
```

When `httpClient` is omitted, `LlmService` builds a Guzzle client with a **600s timeout**
shared by both providers. That number used to be OpenAI-only-60s / Anthropic-only-600s (a
per-request Guzzle option on the Anthropic call, since Claude tool-use responses can run
long) — PSR-18's `sendRequest()` takes only a `RequestInterface`, with no per-call options
bag, so a per-provider timeout has no seam to hang off anymore. The default now simply
covers the slower case for both. Inject your own `ClientInterface` if you need the tighter
OpenAI-side timeout back.

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/core` | `LlmServiceInterface` — the seam `LlmService` implements. |
| Tool execution | `milpa/tool-runtime` | `ToolRegistry`, `ToolContext`, `ToolResult`, channel rendering — the pipeline `McpClientService` and `AgentOrchestrator` drive. |
| **Gateway** | **`milpa/ai-gateway`** (this package) | The concrete `LlmService` (OpenAI + Anthropic, format translation both ways), `McpClientService` (registry facade), and `AgentOrchestrator` (the ask-act loop). |
| Your app | your host / plugins | API keys and secrets management, the PSR-3 logger you wire in, and any channel-specific glue (Telegram, web chat, CLI) around `AgentOrchestrator::run()`. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.3**
- [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) **^0.2**
- [`guzzlehttp/guzzle`](https://packagist.org/packages/guzzlehttp/guzzle) **^7.10** — the
  default PSR-18 implementation `LlmService` falls back to when no `ClientInterface` is
  injected (also brings `guzzlehttp/psr7`, used as the default PSR-17 factory)
- `psr/http-client`, `psr/http-factory`, `psr/http-message` — the interfaces `LlmService`'s
  constructor is typed against
- [`psr/log`](https://packagist.org/packages/psr/log) **^3**

## Security note

`LlmService` can log provider request/response detail at `debug` level, including a slice of
the **raw** LLM response body — never enable that logging in production. See
[SECURITY.md](SECURITY.md) for the specifics.

## Documentation

**Full API reference: [getmilpa.github.io/ai-gateway](https://getmilpa.github.io/ai-gateway/)** —
generated straight from the source DocBlocks and dressed with the Milpa design system.

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Please report security
issues via [SECURITY.md](SECURITY.md), and note that this project follows a
[Code of Conduct](CODE_OF_CONDUCT.md).

## License

[Apache-2.0](LICENSE) © TeamX Agency.

---

Milpa is designed, built, and maintained by **[TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=ai-gateway)**.
