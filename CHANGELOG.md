# Changelog

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
