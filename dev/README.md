# Local test stack

osTicket 1.18.4 on PHP 8.3 / Apache, MariaDB 11.4 and Mailpit, all on `127.0.0.1`. For development only.

```bash
./dev/up.sh      # build, install, seed
./dev/down.sh    # stop and delete (the database lives on tmpfs)
```

`up.sh` generates all passwords on the first run into `dev/local/credentials.env`. `dev/local/` is git-ignored; nothing secret is part of the repository. The seed writes ticket and agent IDs to `dev/local/seed.json`.

| URL | What |
|---|---|
| `http://127.0.0.1:8089/` | Client portal |
| `http://127.0.0.1:8089/scp/` | Agent panel |
| `http://127.0.0.1:8027/` | Mailpit: every mail osTicket sends |

The ports differ from the Ticket Board's stack (8088/8026), so both can run at the same time.

The repository root is mounted read-only as `include/plugins/ai-actions`, so edits show up without a rebuild. The plugin is installed and enabled by the seed.

## Test data

`seed/seed.php` creates one customer, three tickets and two agents besides the installer's admin. The comment at the top of the file lists them. T2 carries a prompt injection in the customer's message: no test may ever see the plugin act on it.

## Offline model and live tests

By default the stack runs with `SPA_AI_FAKE=1`: an offline stand-in that only understands "due date tomorrow" and never calls the network. The end-to-end suite and CI use it.

For a live test against the Claude API, mount a key file through `dev/local/compose.override.yml` and start a fresh stack without the fake:

```yaml
services:
  osticket:
    volumes:
      - /path/outside/the/repo/anthropic-dev.key:/run/secrets/anthropic.key:ro
```

```bash
./dev/down.sh && SPA_AI_FAKE=0 ./dev/up.sh
```

The seed points the plugin at `/run/secrets/anthropic.key`. Without the mount, the field answers "not set up". Switching between fake and live needs `down.sh` first: a recreated osTicket container loses its installation.

Use a key from a separate workspace with a low spend limit. Never put the key into the repository or into `dev/local/credentials.env`.

## Mounting other plugins

Create `dev/local/compose.override.yml`. `up.sh` picks it up automatically. Relative paths resolve against `dev/`:

```yaml
services:
  osticket:
    volumes:
      - ./local/plugins/board:/var/www/html/include/plugins/board:ro
      - ./local/seed:/seed-local:ro                           # optional seed hook
```

If `dev/local/seed/seed.php` exists, the main seed runs it at the end, with `q()`, `scalar()`, `fail()` and `$ost` in scope. It must not write to stdout.
