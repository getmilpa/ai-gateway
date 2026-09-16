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

With `AgentOrchestrator(lazyTools: true)`, `describe_tool` lists discoverable names and
descriptions. Describing a tool makes it callable with its full input schema on subsequent
requests. Undiscovered tools are not advertised as callable empty-object signatures. Discovery
does not authorize execution: the same tool registry and gates still judge every real call.
An empty catalogue exposes no discovery tool. The default full catalogue is unchanged.

```bash
composer require milpa/ai-gateway
```

## Semantic recovery

An optional `ProgressProbe` owns the evidence and the recovery window. Its additive `recovery`
field reports `pending`, `recovered`, or `exhausted`. Pending recovery survives successful tool
calls and missing observations; only measured recovery clears it. Exhaustion returns
`AgentOrchestrator::PROGRESS_STALLED` with the producer's receipt before another model call.
Preparation can therefore lead to real work without making repeated bookkeeping count as work.
The total step budget remains in force. Probes omitting the field keep their previous behavior;
confirmation, debt and abandonment retain their exits, and abandonment does not reset recovery.

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

## Run termination

`run(): string` keeps its existing response text and exceptions. After calling the base
loop, read `$orchestrator->termination()` for the producer's `RunTermination` observation:

```php
use Milpa\AiGateway\RunEnd;

try {
    $answer = $orchestrator->run('Inspect the app.');
} finally {
    $end = $orchestrator->termination();
    // $end?->reason === RunEnd::FinalAnswer means the final-answer branch returned.
    // It does not mean the requested work is complete or approved.
}
```

`reason` distinguishes `final_answer`, `tool_refused`, `confirmation_required`, `blocked`,
`steps_exhausted`, `progress_stalled`, `house_debt`, `invalid_response`, `interrupted`,
`output_truncated`, and `failed`. For `progress_stalled`, `receipt` carries the probe's original
receipt. `toArray()` exports both fields. A refusal and a final answer can have identical text;
the cause comes from the executed branch, never from matching that text.

The observation resets to `null` when each base run begins and is available after a return or
an exception escapes; the same exception object still propagates. Captured observations retain
their values when the instance is reused. The getter describes the latest **base-loop** run;
subclasses that replace `run()` must not present an earlier base-loop observation as a new one.
Concurrent or reentrant runs on the same orchestrator are not supported. Session questions,
completion evidence and authorization remain the host's responsibility.

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

`generateResponse(maxTokens: 4096)` requires a positive output limit. It sends
`max_completion_tokens` to OpenAI-compatible endpoints and `max_tokens` to Anthropic.
Oversized structured tool exceptions use a bounded `milpa.tool-failure-window/v1` projection.
Its outer `ok:false` describes the failed call; `preview` preserves the decoded error where it
fits, and `partial` plus `omitted` identify removed fields by JSON Pointer, character count and
SHA-256. `original` identifies the complete error by size and hash. The session recorder keeps
that original receipt; the projection does not write or replace it. Large object string fields
may be omitted, while arrays and scalar facts are never rewritten. If the structure still does
not fit, `preview:null` and a root omission report that it is unavailable. The same applies
when number spellings cannot survive PHP decoding and encoding unchanged. A preview is not a
complete receipt or authorization. Short errors, unsupported JSON and successful results keep
their existing paths; no context or per-result budget is increased.

When the provider reports output truncation (`length` / `max_tokens`), buffered and SSE
responses throw `OutputTruncatedException` before any tool call from that response can execute.
Anthropic's `model_context_window_exceeded` is also treated as truncation. The exception exposes
`provider`, `stopReason` and the requested `maxTokens`; it does not expose partial tool
arguments. Usage is still observed. There is no automatic retry, including during the guided
retry of a degenerate answer. Previously completed steps remain completed.

## Stopping the loop before it acts: `ToolCallGate`

The loop above executes what the model asked for. `ToolCallGate` is the seam that lets somebody
else decide, before each call, whether it proceeds — and `ToolCallRecorder` is told after, with what
the tool answered. Both contracts live in `milpa/tool-runtime` (`Milpa\ToolRuntime\Gate`), where
every caller of tools already depends: a model is one caller, a governed door opened by a human is
another, and both ask the same question (greenhouse decisions/0225). This package keeps the two names
as interfaces that extend the base, so whoever implemented them here is still a gate or a recorder
wherever the base is asked for.

```php
use Milpa\ToolRuntime\Gate\ToolCallGate;
use Milpa\ToolRuntime\Gate\ToolCallRecorder;

interface ToolCallGate
{
    // The reason this call does not proceed, or null if it does.
    public function refuse(string $tool, array $arguments): ?string;
}

interface ToolCallRecorder
{
    // Told after the call, with the rendered result and whether it succeeded.
    public function recorded(string $tool, array $arguments, string $result, bool $ok): void;
}
```

`refuse()` runs before the call and `recorded()` after it. The two halves are not symmetric on
purpose: refusing is about INTENTION (what the caller is about to do), recording is about OUTCOME
(what happened). A refusal is not a tool error: `McpClientService::callTool()` throws
`ToolCallRefusedException` (which extends tool-runtime's `ToolCallRefused`) and the orchestrator
catches it apart, before any generic catch, and ends the turn. The gate is an interface here and
nothing else: this package brings no policy of its own — `milpa/app-runtime` implements it with the
session's permissions, the autonomy mode and the signatures.

## The table: `OptionTable`

Since 0.5 the loop can operate against a **table** — the set of options the agent currently has in
front of it — through one port with two questions that look alike and are not:

```php
interface OptionTable
{
    public function remove(string $option, string $code, ?string $message = null): void;
    public function removed(): array;          // the PROJECTION: what the model sees
    public function wasRemoved(string $option): bool;  // the FACT: did an authority declare it gone?
}
```

The catalogue the model sees is re-derived **every step** from the registry minus `removed()` — it
is a projection, never a snapshot. And a refusal that *removed* the option no longer ends the run:
there is nothing left to route around, so the reason goes back to the model and the loop continues
with a different table. Both came out of measurement: a frozen catalogue made "did the agent re-read
the world?" unanswerable, and a run-ending refusal turned every denial into a shutdown (0 of 32
runs recovered).

`SecondOpinionGate` also stopped being quiet anywhere: an empty answer, a verdict-less answer and a
failed judge each say so with their own cause, a denial leaves a witness on stderr **outside** the
stream it writes to, and an ALLOW logs nothing — approving silently is correct; failing silently
was being counted as approval.

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

## Asking the provider for its context window: `ProviderWindow`

The number that governs compaction used to be a human assertion nothing verified — an app declared
a context window, and a declaration that was too large went unnoticed until the provider rejected
the prompt mid-run. `ProviderWindow` asks instead:

```php
use Milpa\AiGateway\ProviderWindow;

$window = new ProviderWindow('http://localhost:11438');
$window->tokens();   // 32768, or null when the provider did not say
```

Three things it does not do, each on purpose:

- **It reads only the window the server ALLOCATED.** Measured against `qwen3.8-27b` on llama.cpp,
  `GET /v1/models` answers `data[0].meta.n_ctx` **32768** next to `n_ctx_train` **262144** — eight
  times apart. A reader that took the training window would believe it had 262k of room and blow up
  at 32k. When the training window is all a provider exposes, the answer is `null`.
- **It never guesses and never raises.** Unreachable, non-JSON, JSON without the field, a zero, a
  negative, a float, a numeric string — every one of them resolves to `null`. The caller asked for a
  better number, not for a new way to fail.
- **It asks once.** The answer is memoised per instance, the silence included: asking is egress.

It tries `GET /v1/models` first — the OpenAI-compatible surface this gateway already drives, so any
base URL it can talk to answers there by construction — and falls back to llama.cpp's `GET /props`
(`default_generation_settings.n_ctx`), which carries the same figure but cannot be primary: the
measured payload declares `"endpoint_props": false` about itself.

The network is a seam, `callable(string): ?string`, so the whole reader is testable offline:

```php
$window = new ProviderWindow('http://provider.test', fn (string $url): ?string => $recordedBody);
```

`milpa/app-runtime` composes this with whatever the app declared and keeps **the smaller of the
two** (greenhouse decisions/0233): a declaration is intent, the measurement is a ceiling that
cannot be exceeded.

## What lives where

| Layer | Package | Owns |
|-------|---------|------|
| Contracts | `milpa/tool-runtime` | `LlmServiceInterface` — the seam `LlmService` implements. |
| Tool execution | `milpa/tool-runtime` | `ToolRegistry`, `ToolContext`, `ToolResult`, channel rendering — the pipeline `McpClientService` and `AgentOrchestrator` drive. |
| **Gateway** | **`milpa/ai-gateway`** (this package) | The concrete `LlmService` (OpenAI + Anthropic, format translation both ways), `McpClientService` (registry facade), `AgentOrchestrator` (the ask-act loop), and `ProviderWindow` (the provider's allocated context window). |
| Your app | your host / plugins | API keys and secrets management, the PSR-3 logger you wire in, and any channel-specific glue (Telegram, web chat, CLI) around `AgentOrchestrator::run()`. |

## Requirements

- PHP **≥ 8.3**
- [`milpa/core`](https://packagist.org/packages/milpa/core) **^0.6**
- [`milpa/tool-runtime`](https://packagist.org/packages/milpa/tool-runtime) **^0.5**
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

[Apache-2.0](LICENSE) © Rodrigo Vicente - TeamX Agency.

---

Milpa is designed, built, and maintained by **[Rodrigo Vicente - TeamX Agency](https://teamx.agency/?utm_source=github&utm_medium=readme&utm_campaign=milpa&utm_content=ai-gateway)**.

## Producer-aware tool result limits

Each native tool invocation receives a `ResultBudget` through its `ToolContext`. The gateway derives the allowance from the declared context window using its existing rule (8000 characters for 32768 tokens; 6144 for 8192) and uses the same JSON encoder when delivering array results. A contextual handler can fit a complete result, including metadata, before returning it.

This does not raise the existing ceiling or change scope checks, consent, progress recovery, or history projection. Legacy strings keep their existing direct rendering; `ToolResult` objects keep their renderer path. Producers that ignore the optional budget retain the existing truncation behavior. The constraint lasts for one synchronous governed call.
