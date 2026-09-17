# Changelog

## [0.29.0](https://github.com/getmilpa/ai-gateway/compare/v0.28.0...v0.29.0) (2026-09-17)


### Features

* carry an explicit output budget through the native agent loop ([e20b0c9](https://github.com/getmilpa/ai-gateway/commit/e20b0c9b2a82b89c7dca9621e4a2d7bf6787349f))

## [0.28.0](https://github.com/getmilpa/ai-gateway/compare/v0.27.0...v0.28.0) (2026-09-17)


### Features

* request finite structured output through an explicit client clone (greenhouse 0421) ([b628f7e](https://github.com/getmilpa/ai-gateway/commit/b628f7ea9ef3fb682ebd2b4c483bcd2cc56eb665))

## [0.27.0](https://github.com/getmilpa/ai-gateway/compare/v0.26.0...v0.27.0) (2026-09-16)


### Features

* judge optional final answers independently of progress (greenhouse 0418/0736) ([b0247b3](https://github.com/getmilpa/ai-gateway/commit/b0247b3ccd1f7a45bc43efdf7f130b69004558c8))

## [0.26.0](https://github.com/getmilpa/ai-gateway/compare/v0.25.0...v0.26.0) (2026-09-16)


### Features

* deliver the native result budget to tool producers ([f901982](https://github.com/getmilpa/ai-gateway/commit/f901982cf67a58b6d1ca4c289383baa5acb78ca5))
* deliver the native result budget to tool producers ([e162cfa](https://github.com/getmilpa/ai-gateway/commit/e162cfaea89b58af277029cfa913ec2cbe973d84))


### Bug Fixes

* keep result truncation on the UTF-8 budget ruler ([c7e06e5](https://github.com/getmilpa/ai-gateway/commit/c7e06e534d5cf9f4be2da60cceb6e3dd83801e60))

## [0.25.0](https://github.com/getmilpa/ai-gateway/compare/v0.24.5...v0.25.0) (2026-09-14)


### Features

* expose producer-owned run termination ([8610512](https://github.com/getmilpa/ai-gateway/commit/86105123ce9599b025b92eb3b3410bbac3403110))
* expose producer-owned run termination ([bc0abec](https://github.com/getmilpa/ai-gateway/commit/bc0abec07ea4695b3ef28854cfaf9d4e7d3c8725))

## [0.24.5](https://github.com/getmilpa/ai-gateway/compare/v0.24.4...v0.24.5) (2026-09-14)


### Bug Fixes

* preserve structured failures within the model window ([80c1f7c](https://github.com/getmilpa/ai-gateway/commit/80c1f7c76aff9e7462b315aff2988428288d4c06))
* preserve structured tool failures in the bounded window (greenhouse 0697) ([a036355](https://github.com/getmilpa/ai-gateway/commit/a036355a6c747275e986a5be7eabfc0facb0f8e1))

## [0.24.4](https://github.com/getmilpa/ai-gateway/compare/v0.24.3...v0.24.4) (2026-09-13)


### Bug Fixes

* enforce active option withdrawal in model tool calls ([a9c4ea2](https://github.com/getmilpa/ai-gateway/commit/a9c4ea2d2f86a62d129f36e2fc59407f01f8a2c4))
* enforce current withdrawal in model tool calls ([16a723b](https://github.com/getmilpa/ai-gateway/commit/16a723b1946497ae04d79c5426f45d2500d1296d))

## [0.24.3](https://github.com/getmilpa/ai-gateway/compare/v0.24.2...v0.24.3) (2026-09-12)


### Bug Fixes

* retain bounded semantic recovery ([837a687](https://github.com/getmilpa/ai-gateway/commit/837a6870f1b8071622eaa046eda76af4c9fec704))
* retain bounded semantic recovery (greenhouse 0343) ([c876437](https://github.com/getmilpa/ai-gateway/commit/c8764370c24b4b8c4c7585be38ae8eb06ed9258c))

## [0.24.2](https://github.com/getmilpa/ai-gateway/compare/v0.24.1...v0.24.2) (2026-09-12)


### Bug Fixes

* expose complete schemas for callable lazy tools ([c9b6aca](https://github.com/getmilpa/ai-gateway/commit/c9b6acab05d0b51d47d1bc510164493fdf566173))
* expose complete schemas for callable lazy tools ([9c0cbfb](https://github.com/getmilpa/ai-gateway/commit/9c0cbfb6d6fc734b114d751bbcafc4b8fd9ab1e1))

## [0.24.1](https://github.com/getmilpa/ai-gateway/compare/v0.24.0...v0.24.1) (2026-09-12)


### Bug Fixes

* honor output limits and reject truncated completions ([6dc89cf](https://github.com/getmilpa/ai-gateway/commit/6dc89cf09c984c6c7a7ac176112e197bf15f0eb9))
* honor output limits and reject truncated completions ([2e9cada](https://github.com/getmilpa/ai-gateway/commit/2e9cadad73704d7cfe2868ddb46a6436f4be4969))

## [0.24.0](https://github.com/getmilpa/ai-gateway/compare/v0.23.0...v0.24.0) (2026-09-10)


### Features

* the house asks whether a model answers, instead of reading what config claims ([#67](https://github.com/getmilpa/ai-gateway/issues/67)) ([dd5d378](https://github.com/getmilpa/ai-gateway/commit/dd5d378d4b2ac670e479303ab051047051820aec))

## [0.23.0](https://github.com/getmilpa/ai-gateway/compare/v0.22.2...v0.23.0) (2026-09-08)


### Features

* the house asks the provider its window, and only the allocated one counts ([#65](https://github.com/getmilpa/ai-gateway/issues/65)) ([1d2b0ea](https://github.com/getmilpa/ai-gateway/commit/1d2b0eac9804c4a980c22509da113b3ef1a23062))

## [0.22.2](https://github.com/getmilpa/ai-gateway/compare/v0.22.1...v0.22.2) (2026-09-08)


### Bug Fixes

* what the adversarial review of the gate move confirmed here ([#62](https://github.com/getmilpa/ai-gateway/issues/62)) ([0dd1846](https://github.com/getmilpa/ai-gateway/commit/0dd1846eb336dd656bef5241e5f8070a0242eacf))

## [0.22.1](https://github.com/getmilpa/ai-gateway/compare/v0.22.0...v0.22.1) (2026-09-08)


### Bug Fixes

* the second opinion's floor is documented on the base gate too ([#60](https://github.com/getmilpa/ai-gateway/issues/60)) ([e910d6d](https://github.com/getmilpa/ai-gateway/commit/e910d6de23fd9650ddf4363813089703fda68103))

## [0.22.0](https://github.com/getmilpa/ai-gateway/compare/v0.21.0...v0.22.0) (2026-09-08)


### Features

* the gate comes from milpa/tool-runtime — this package consumes it ([#58](https://github.com/getmilpa/ai-gateway/issues/58)) ([9826982](https://github.com/getmilpa/ai-gateway/commit/9826982cb1801439525a8edc2459f545108e1b7c))

## [0.21.0](https://github.com/getmilpa/ai-gateway/compare/v0.20.0...v0.21.0) (2026-09-07)


### Features

* the per-result budget comes from the window, not from a constant ([#56](https://github.com/getmilpa/ai-gateway/issues/56)) ([b5cca55](https://github.com/getmilpa/ai-gateway/commit/b5cca55a26c52f44cf81839bcc5d26083f0c4a6e))

## [0.20.0](https://github.com/getmilpa/ai-gateway/compare/v0.19.0...v0.20.0) (2026-09-04)


### Features

* request usage on streamed calls so the token cost is reported ([#54](https://github.com/getmilpa/ai-gateway/issues/54)) ([9473883](https://github.com/getmilpa/ai-gateway/commit/9473883adc9ad641cc82d59e2e0d0d730cbe28ed))

## [0.19.0](https://github.com/getmilpa/ai-gateway/compare/v0.18.1...v0.19.0) (2026-09-04)


### Features

* stream reasoning deltas, tagged, so a surface can show the thinking ([#52](https://github.com/getmilpa/ai-gateway/issues/52)) ([cf5fcb6](https://github.com/getmilpa/ai-gateway/commit/cf5fcb6f86e18fa28dd36056cc9ea87a9db1b89d))

## [0.18.1](https://github.com/getmilpa/ai-gateway/compare/v0.18.0...v0.18.1) (2026-09-02)


### Bug Fixes

* the provider flake retries twice — double flakes were measured live twice ([#50](https://github.com/getmilpa/ai-gateway/issues/50)) ([bf81a8e](https://github.com/getmilpa/ai-gateway/commit/bf81a8eb24f19d28b68bf0f7892a4954ff93f49d))

## [0.18.0](https://github.com/getmilpa/ai-gateway/compare/v0.17.2...v0.18.0) (2026-09-02)


### Features

* self-heal the exceed-context 400 by the provider's own numbers ([#48](https://github.com/getmilpa/ai-gateway/issues/48)) ([a73a380](https://github.com/getmilpa/ai-gateway/commit/a73a380708e5a04971c2f28092c437e5a6879cb7))

## [0.17.2](https://github.com/getmilpa/ai-gateway/compare/v0.17.1...v0.17.2) (2026-09-02)


### Bug Fixes

* one retry for the provider flake caused by the model's own malformed tool-call output ([#46](https://github.com/getmilpa/ai-gateway/issues/46)) ([31ea6e6](https://github.com/getmilpa/ai-gateway/commit/31ea6e606de98d42bb4e69698dd80bdec5a33974))

## [0.17.1](https://github.com/getmilpa/ai-gateway/compare/v0.17.0...v0.17.1) (2026-09-02)


### Bug Fixes

* model-emitted non-array tool arguments normalize to the empty set ([#44](https://github.com/getmilpa/ai-gateway/issues/44)) ([6ef232b](https://github.com/getmilpa/ai-gateway/commit/6ef232bb20aff76975fb5ddddba5473a2fcb91e3))

## [0.17.0](https://github.com/getmilpa/ai-gateway/compare/v0.16.1...v0.17.0) (2026-09-02)


### Features

* the intra-leg budget — each call's projection bounded to the declared context ([#42](https://github.com/getmilpa/ai-gateway/issues/42)) ([fc18e8d](https://github.com/getmilpa/ai-gateway/commit/fc18e8da61f826abef229dafaa9e79ced47cec8e))

## [0.16.1](https://github.com/getmilpa/ai-gateway/compare/v0.16.0...v0.16.1) (2026-09-02)


### Bug Fixes

* the stall notice rides as a user-role steering line — providers reject non-leading system messages ([#40](https://github.com/getmilpa/ai-gateway/issues/40)) ([e345da7](https://github.com/getmilpa/ai-gateway/commit/e345da791d0deb651ea8d5e03cdb49f938182a4f))

## [0.16.0](https://github.com/getmilpa/ai-gateway/compare/v0.15.0...v0.16.0) (2026-09-02)


### Features

* the forced choice — a stalled probe ends option E (greenhouse decisions/0185) ([#38](https://github.com/getmilpa/ai-gateway/issues/38)) ([9943eb3](https://github.com/getmilpa/ai-gateway/commit/9943eb388f6906ae4dcd06d44235177c92949f02))

## [0.15.0](https://github.com/getmilpa/ai-gateway/compare/v0.14.1...v0.15.0) (2026-09-01)


### Features

* transport retry-once and a degenerate-answer guard ([#36](https://github.com/getmilpa/ai-gateway/issues/36)) ([c55d0be](https://github.com/getmilpa/ai-gateway/commit/c55d0bee5ee2bebd5f6f4a8098ddc7ae3ce866b2))

## [0.14.1](https://github.com/getmilpa/ai-gateway/compare/v0.14.0...v0.14.1) (2026-08-31)


### Bug Fixes

* **orchestrator:** bound each tool result to the model window in the inner loop ([#34](https://github.com/getmilpa/ai-gateway/issues/34)) ([a9bfc79](https://github.com/getmilpa/ai-gateway/commit/a9bfc79d312e0349d33607c282a1044466d86054))

## [0.14.0](https://github.com/getmilpa/ai-gateway/compare/v0.13.0...v0.14.0) (2026-08-30)


### Features

* publish what a model call reasoned via an optional ReasoningObserver seam ([#32](https://github.com/getmilpa/ai-gateway/issues/32)) ([dd98be6](https://github.com/getmilpa/ai-gateway/commit/dd98be6422a61f1dbfc4c0b04a8c5badb6e7f1a0))

## [0.13.0](https://github.com/getmilpa/ai-gateway/compare/v0.12.0...v0.13.0) (2026-08-30)


### Features

* a lazy toolbox — tools arrive by name and description, schemas on demand (opt-in) ([#30](https://github.com/getmilpa/ai-gateway/issues/30)) ([7f79f13](https://github.com/getmilpa/ai-gateway/commit/7f79f13c02f92b8e2ecab7a7fb121893923f7fe3))

## [0.12.0](https://github.com/getmilpa/ai-gateway/compare/v0.11.0...v0.12.0) (2026-08-28)


### Features

* publish what a model call cost via an optional ReturnObserver seam ([#28](https://github.com/getmilpa/ai-gateway/issues/28)) ([569ef70](https://github.com/getmilpa/ai-gateway/commit/569ef70dbd223aeda4fdb8d2882c056f7217218f))

## [0.11.0](https://github.com/getmilpa/ai-gateway/compare/v0.10.0...v0.11.0) (2026-08-25)


### Features

* stream the OpenAI-compatible call for honest per-chunk progress ([#26](https://github.com/getmilpa/ai-gateway/issues/26)) ([b5f681a](https://github.com/getmilpa/ai-gateway/commit/b5f681a1d403e816d9f12ca1aea2f9c6ecd5fae7))

## [0.8.2](https://github.com/getmilpa/ai-gateway/compare/v0.8.1...v0.8.2) (2026-08-04)


### Bug Fixes

* **agent:** una llamada mal formada deja de pasar por respuesta del agente ([f8fb330](https://github.com/getmilpa/ai-gateway/commit/f8fb330221fb34962245bebe32c27fab0cac6b95))

## [0.8.1](https://github.com/getmilpa/ai-gateway/compare/v0.8.0...v0.8.1) (2026-08-04)


### Bug Fixes

* **composer:** declarar type milpa-capability para que el paquete sea descubrible por lo que es ([d1ba788](https://github.com/getmilpa/ai-gateway/commit/d1ba788c12ad71e2909b00d00a32fc2a63970d3e))

## [0.8.0](https://github.com/getmilpa/ai-gateway/compare/v0.7.0...v0.8.0) (2026-08-03)


### Features

* el plan del agente se puede volver a poner delante del modelo en cada paso ([a58339c](https://github.com/getmilpa/ai-gateway/commit/a58339c926cdef89ab2f1f5305c49aa67411afae))

## [0.7.0](https://github.com/getmilpa/ai-gateway/compare/v0.6.0...v0.7.0) (2026-08-02)


### Features

* the option table — a per-step projection, and a gate that is never silent ([0f7b213](https://github.com/getmilpa/ai-gateway/commit/0f7b213c3eb32cf3929683bdfa0faf8456d0f752))

## [0.6.0](https://github.com/getmilpa/ai-gateway/compare/v0.5.0...v0.6.0) (2026-08-02)


### Features

* a refusal that names the observable alternative ([9d20bb9](https://github.com/getmilpa/ai-gateway/commit/9d20bb9f23f09c3c0d43bff3206bd15807b535c3))

## [0.5.0](https://github.com/getmilpa/ai-gateway/compare/v0.4.2...v0.5.0) (2026-08-02)


### Features

* SecondOpinionGate — a second reader between the proposed call and its execution ([c4d5eec](https://github.com/getmilpa/ai-gateway/commit/c4d5eec7c19fb8be522549f15c83e4797820f804))

## [0.4.2](https://github.com/getmilpa/ai-gateway/compare/v0.4.1...v0.4.2) (2026-08-01)


### Bug Fixes

* the capability contract speaks English ([d0fcbf2](https://github.com/getmilpa/ai-gateway/commit/d0fcbf270be64f60cb33e2f20675c2cfaef003d6))

## [0.4.1](https://github.com/getmilpa/ai-gateway/compare/v0.4.0...v0.4.1) (2026-08-01)


### Bug Fixes

* este paquete declara que aporta ([d827d06](https://github.com/getmilpa/ai-gateway/commit/d827d0668bb4a51bf85b140ddd3f09dacd94c0fb))

## [0.4.0](https://github.com/getmilpa/ai-gateway/compare/v0.3.1...v0.4.0) (2026-08-01)


### Features

* ToolCallGate — alguien puede decidir ANTES de que el bucle actue ([74ff5b4](https://github.com/getmilpa/ai-gateway/commit/74ff5b463dcda5a42bbd23c1264992549116dc35))

## [0.3.1](https://github.com/getmilpa/ai-gateway/compare/v0.3.0...v0.3.1) (2026-07-31)


### Features

* point the agent at your own endpoint ([719fce1](https://github.com/getmilpa/ai-gateway/commit/719fce19aa2eee429168c2cb91439ba8670ecbee))

## [0.3.0](https://github.com/getmilpa/ai-gateway/compare/v0.2.3...v0.3.0) (2026-07-30)


### Features

* require milpa/tool-runtime ^0.9 ([027f5c5](https://github.com/getmilpa/ai-gateway/commit/027f5c5e5ee6ab630fd8ce2ab92c2fb2bfaf5c31))

## [0.2.3](https://github.com/getmilpa/ai-gateway/compare/v0.2.2...v0.2.3) (2026-07-30)


### Bug Fixes

* catch up with the family's published versions ([684fa53](https://github.com/getmilpa/ai-gateway/commit/684fa53e4e2cf0f3d92be02b6c9e2d8235f8691b))

## [0.2.2](https://github.com/getmilpa/ai-gateway/compare/v0.2.1...v0.2.2) (2026-07-12)


### Bug Fixes

* receive milpa/core 0.6 — pin bump ([ae85dc9](https://github.com/getmilpa/ai-gateway/commit/ae85dc912f84a44361302c4197c58a34dc5d50d9))

## [0.2.1](https://github.com/getmilpa/ai-gateway/compare/v0.2.0...v0.2.1) (2026-07-08)


### Bug Fixes

* require milpa/core ^0.5 and milpa/tool-runtime ^0.5 ([e221b3b](https://github.com/getmilpa/ai-gateway/commit/e221b3b283d2d64f667b5a02faa8f8052a4c2dcb))

## [0.2.0](https://github.com/getmilpa/ai-gateway/compare/v0.1.1...v0.2.0) (2026-07-08)


### ⚠ BREAKING CHANGES

* injectable PSR-18 HTTP client + explicit provider-error guard

### Features

* injectable PSR-18 HTTP client + explicit provider-error guard ([22c4b73](https://github.com/getmilpa/ai-gateway/commit/22c4b73c3bb8339bf70fcbda1d226456e1f9cd45))

## [0.1.1](https://github.com/getmilpa/ai-gateway/compare/v0.1.0...v0.1.1) (2026-07-08)


### Bug Fixes

* require milpa/tool-runtime ^0.3 ([8cb208b](https://github.com/getmilpa/ai-gateway/commit/8cb208bf89b3b7fc43c1132cbc909d614e36cf81))

## 0.1.0 (2026-07-07)


### Features

* milpa/ai-gateway initial public release ([db6c534](https://github.com/getmilpa/ai-gateway/commit/db6c5345c3db8f88a0bcd2b090e91a28fe550ea3))


### Miscellaneous Chores

* release 0.1.0 ([5ffcb1f](https://github.com/getmilpa/ai-gateway/commit/5ffcb1f1c85689885f9d7c0dbf1a937b38809eda))
