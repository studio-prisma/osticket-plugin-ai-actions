# Security Policy

## Supported Versions

Only the latest minor release receives security fixes. Older tags are archived, not maintained.

| Version | Supported |
|---|---|
| `<latest minor>.x` | yes |
| everything older | no |

## Reporting a Vulnerability

Use **[GitHub Security Advisories](https://github.com/studio-prisma/osticket-plugin-ai-actions/security/advisories/new)** — private by default. Do not open a public issue for a suspected vulnerability.

Include:

- affected version and host platform version
- what an attacker gains, not only what misbehaves
- reproduction steps, ideally against a clean install

Expected response: an acknowledgement within 7 days, an assessment within 30. This is a spare-time project, not a vendor with an on-call rotation — that is the honest number, not a target.

## In Scope

State what a real finding looks like for *this* project. Be specific enough that a reporter can decide before writing the report. Examples of the level of detail expected:

- bypassing the check this component exists to enforce
- injection reachable from unauthenticated input
- credentials or tokens leaking into logs, error pages, or the built artifact
- privilege escalation between the roles the host platform defines

## Out of Scope

Documented design decisions are not findings. Naming them here saves everyone a round trip.

- issues in the host platform itself — report those upstream
- anything requiring an already-compromised host or admin account
- missing hardening that the host platform, not this project, is responsible for
- fail-open behaviour that is documented as configurable and defaults to fail-closed
- automated scanner output without a working proof of concept

## Handling of Secrets

No credentials belong in this repository — not in tests, not commented out, not in fixtures. A secret that reached a commit is rotated first and removed second. The history stays readable either way.

## Disclosure

Coordinated. A fix ships first, the advisory follows once a patched release is available. Reporters are credited unless they ask not to be.
