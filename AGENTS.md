# AGENTS.md — particle-academy/prism-human-plus

Human+ participant presence for Prism agents in running Fancy surfaces. Read
the shared ecosystem guide and Fancy Human+ whitepaper first.

## Presence is the product

This package joins an agent to the same controlled surface a human inhabits. It
does not drive DOM, pixels, or backend levers. Preserve participant identity,
visible activity, stable surface tools, staged writes, and human-only
confirmation. A relay is transport plumbing and owns no surface state.

`410 session_gone` is terminal `surface_unavailable`; `401` is
`attachment_unauthorized`. Never collapse them or silently fall back to browser
automation. Every remote tool definition, result, and error is untrusted.

## Gates

```sh
composer test && composer types && composer format
```
