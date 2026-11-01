# Contributing

Thanks for taking the time. This project is small and maintained in spare time — the rules below exist so a pull request can be reviewed in one sitting.

## Before you open a pull request

- Open an issue first for anything beyond a typo. A rejected PR wastes more of your time than a rejected issue.
- One change per PR. A refactor bundled with a fix is two PRs.
- Security issues never go into a public PR or issue — see [SECURITY.md](SECURITY.md).

## Development setup

```bash
git clone https://github.com/studio-prisma/osticket-plugin-ai-actions.git
cd osticket-plugin-ai-actions
# runtime setup — replace with what this project actually needs
```

Requirements are listed in [README.md](README.md#requirements). State versions you have verified, not versions you expect to work.

## What a change has to pass

```bash
./tests/run-all.sh
```

CI runs the same checks on every push and pull request. The aggregating job is called **Gate**; it is the only status check the branch protection requires, and it is green only when every other job is.

A red pipeline is not mergeable. If a check fails for a reason unrelated to your change, say so in the PR instead of rerunning until it passes.

## Code conventions

- Match the surrounding style. This project does not have a formatter config for you to argue with.
- No new runtime dependency without a reason in the PR description.
- Comments explain *why*, not *what*. The code already says what.
- No placeholder or example data pointing at real hosts. Use `example.com`, `192.0.2.0/24`, `203.0.113.0/24`.

## Documentation

Documentation is part of the change, not a follow-up.

- Behaviour change → update `README.md` **and** `README.de.md` in the same PR.
- Install or configuration change → update `docs/INSTALL.md` **and** `docs/INSTALL.de.md`.
- New limitation discovered → add it to "Known limitations". A limitation users find in production is a bug report; one they read in advance is a decision.

## Commit messages

[Conventional Commits](https://www.conventionalcommits.org/), imperative, English:

```
feat(verifier): enforce hostname from the siteverify response
fix(login): keep the 403 response a fragment instead of a full page
docs(install): clarify the CSP intersection case
ci: pin actions to major versions
```

## Release process

Maintainer only.

1. `## [Unreleased]` → `## [X.Y.Z] - YYYY-MM-DD`, add a fresh empty `Unreleased` block
2. bump the version in the manifest — it lives in exactly one place
3. `git commit -m "chore(release): vX.Y.Z"`, merge via PR
4. `git tag -a vX.Y.Z -m "vX.Y.Z"` from the merged `main`, push the tag
5. the release workflow builds the artifact and publishes the notes from the changelog

Tags are protected: they cannot be moved or deleted. A wrong tag is corrected with the next patch version, never by overwriting.

## License

By contributing you agree that your contribution is licensed under the [MIT License](LICENSE).
