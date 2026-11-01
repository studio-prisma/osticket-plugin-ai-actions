# Installation

[Deutsche Fassung →](INSTALL.de.md)

**The order below is binding.** Each step has an abort condition. If a step aborts, fix that step — do not continue and hope the next one compensates.

---

## 0. Before you touch anything

| Check | Abort if |
|---|---|
| Host platform version | outside the range in [README.md](../README.md#requirements) |
| Runtime version | outside the verified range |
| Write access to the install directory | you would have to `sudo` a web-server-owned path |
| A restore path for the current state | you cannot roll back within minutes |

**Lockout risk — read this before, not after.** *(Name here what can leave the operator unable to reach their own system: a login gate that fails closed, a rewritten config, a changed auth path. If nothing in this project can lock anyone out, say that explicitly instead of deleting the section.)*

**Kill switch.** *(How to disable this without a working UI — the file to rename, the flag to unset, the table row to delete. Document it here, before the risky option is explained.)*

```bash
# the exact command that undoes everything below
```

---

## 1. Get the package

Download the artifact from the [latest release](https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/latest). Do not install from a source tarball of `main` — `main` is not a release.

**Abort if** the release has no attached artifact, or the version in the filename does not match the tag.

---

## 2. Install

```bash
# exact commands, no placeholders the reader has to guess
```

**Abort if** the target directory already contains a different version — remove it first, do not overlay.

---

## 3. Configure

| Setting | Default | Effect |
|---|---|---|
| ... | ... | ... |

Configure in this order; a later setting can depend on an earlier one being active.

**Abort if** a required value is unavailable. A placeholder value here becomes a production incident later.

---

## 4. Smoke test

The install is not finished until every line below produces the stated result.

| Test | Expected |
|---|---|
| ... | ... |

**Abort if** any row disagrees. Use the kill switch from §0 and investigate offline.

---

## 5. Security gate

Run before declaring the install done. Each row is a decision, not a suggestion.

| Check | Expected | If it differs |
|---|---|---|
| Behaviour when the upstream dependency is unreachable | fail closed | check the fail-mode setting |
| Secrets in logs or error output | none | stop, rotate, then investigate |
| Access from an unauthenticated session | denied | do not go live |

---

## 6. Uninstall

```bash
# exact reversal, including any state left outside the install directory
```

---

## Known limitations

State what is **not** tested, not only what is. A reader who knows the gap can work around it; one who finds it in production files a bug.

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| ... | ... | ... |
