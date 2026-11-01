# The settings chat (admins)

From 0.5.0, admins get the chat bubble on admin pages too (Help Topics, Filters, SLA, Departments, Teams, Lists, Canned Responses, Settings, Logs, Plugins). There it works on the help desk's settings instead of a ticket. The window says **Settings** under the title.

## What it can do

| Area | Create | Change | Disable | Notes |
|---|---|---|---|---|
| Canned responses | ✓ | title, text | ✓ | |
| Help topics | ✓ | name, department, SLA | ✓ | auto-assignment and other options stay |
| List items | ✓ | value | ✓ | in custom lists |
| SLA plans | ✓ | name, grace period | ✓ | |
| Ticket filters | ✓ | name, target, all/any, rules | ✓ | actions: department, priority, help topic, team, SLA |
| Departments | – | name, manager, SLA, public | – | memberships are not changed by the assistant |
| Teams | ✓ | name, lead | ✓ | members added or removed one at a time |

**Never:** deleting anything (only disabling), agents, roles and permissions, email accounts and passwords, API keys, plugins, system and security settings. Asked for any of that, the assistant says it can't.

## How a change runs

1. You write what you want, e.g. "Create a filter: mail from *@supplier.com goes to Purchasing".
2. The assistant plans it. The preview shows each change with **before → after**.
3. **Run** applies it through osTicket's own model code with its checks. Every change also goes to osTicket's system log ("AI admin change").
4. **Undo** works in the chat for 30 seconds and in the plugin's log (`scp/ajax.php/ai/admin`, tab *Log*) for 24 hours. Something the assistant created is disabled by undo, not deleted.

## Why departments and teams are handled differently

osTicket's own save for departments and teams rebuilds the member list from the form's `members[]`. A save without the complete list removes everyone who isn't in it. The assistant therefore never uses that path: it sets only the fields you named on the record and changes team memberships one agent at a time. Department memberships (which carry roles and access) stay with the admin forms.

## Safety

- Admins only; everyone else gets a 404 for the settings conversation.
- No ticket content in this conversation (ticket text is where prompt injection comes from). Names in the settings are treated as data.
- Values are checked again when the plan runs: IDs must still exist, the admin must still be an admin.
