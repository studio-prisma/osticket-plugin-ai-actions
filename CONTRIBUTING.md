# Contributing

Thanks for taking the time. This plugin lets a language model propose changes to helpdesk tickets, so the bar for changes is verification, not volume.

## Before you open a pull request

1. **Open an issue first** for anything beyond a typo or a documentation fix. Discussing an approach is cheaper than reviewing a rewrite.
2. **Changes to what the model sees or may do need a threat-model note.** If your change touches `AiActionsTools`, `AiActionsPlanner`, `AiActionsContext`, `AiActionsChoices`, `AiActionsExecutor` or the controller, describe in the PR what ticket content could now make the plugin do, and why that's acceptable.
3. **Never report a vulnerability as a pull request.** See [SECURITY.md](SECURITY.md).

## Development setup

No build step for the plugin and no PHP dependencies.

```bash
git clone https://github.com/studio-prisma/osticket-plugin-ai-actions.git
cd osticket-plugin-ai-actions
php tests/unit.php
```

For the end-to-end suite you need Docker and Node.js 20 or newer:

```bash
./dev/up.sh                         # osTicket 1.18.4 + MariaDB + Mailpit on 127.0.0.1, offline model
cd tests/e2e && npm ci && npx playwright install chromium && npx playwright test
./dev/down.sh
```

`dev/README.md` explains the stack, a second stack next to the first, live runs against the real model, and how to mount the Ticket Board or a theme.

To build the install package:

```bash
./tools/build-zip.sh                # writes dist/ai-actions-<version>.zip
```

## What a change has to pass

| Check | Command |
|---|---|
| Syntax, all production files | `find . -name '*.php' -not -path './tests/*' -not -path './dev/*' -exec php -l {} \;` |
| JavaScript syntax | `node --check assets/ai-actions.js` |
| Unit tests | `php tests/unit.php` |
| End-to-end | `npx playwright test` in `tests/e2e/` against `dev/up.sh` |

CI runs syntax and unit tests on PHP 8.1 to 8.4, plus the full end-to-end suite with the Ticket Board mounted, on every push and pull request. The aggregating job is called **Gate**; it is the only status check the branch protection requires. A red pipeline isn't mergeable.

A change to the prompt or the tools also needs a live run against the real model with the injection tickets from the security audit (see `dev/README.md`), described in the PR.

## Code conventions

- **PHP 8.1 compatible.** CI lints on 8.1.
- **No runtime dependencies.** No Composer packages, no SDK, no JavaScript library, nothing from a CDN.
- **Write tools are only queued.** Nothing the model calls changes a ticket. New actions go into `AiActionsTools::WRITE`, get a strict schema, a server-side `validate()` and a `summary()` built from validated input and osTicket's labels, never from model text.
- **Actions go through osTicket.** A new action calls the same core method as osTicket's dialog for it, behind the same permission check; the PR names the dialog handler in `include/ajax.tickets.php` it mirrors.
- **Ticket content is data.** It only reaches the model inside the `<ticket_data>` block, through `AiActionsContext::clean()`.
- **One way to the screen.** The JavaScript sets `textContent`; it never builds markup from data. The one `innerHTML` is the static icon.
- **Nothing internal leaves the server.** Model history, old values for undo and plan contents stay in the plugin's tables; the browser gets labels, values and IDs.

## Documentation

Documentation is part of the change, not a follow-up.

- Behaviour change: update `README.md` **and** `README.de.md` in the same PR.
- Install or configuration change: update `docs/INSTALL.md` **and** `docs/INSTALL.de.md`.
- New limitation discovered: add it to "Known limitations".

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/), imperative, English:

```
feat(tools): add a tool to set the help topic
fix(chat): keep the draft text when the limit is reached
docs(install): explain the key file permissions
ci: pin actions to major versions
```

## Release process

Maintainer only.

1. `## [Unreleased]` → `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `Unreleased` block
2. bump the version in `plugin.php` and `AiActionsPlugin::VERSION` (the release workflow refuses a mismatch)
3. `git commit -m "chore(release): vX.Y.Z"`, merge via PR
4. `git tag -a vX.Y.Z -m "vX.Y.Z"` from the merged `main`, push the tag
5. the release workflow builds `ai-actions-X.Y.Z.zip`, attests its provenance and publishes the notes from the changelog

Tags are protected: they cannot be moved or deleted. A wrong tag is corrected with the next patch version, never by overwriting.

## License

By contributing you agree that your contribution is licensed under the [GPL-2.0-or-later](LICENSE), like osTicket.
