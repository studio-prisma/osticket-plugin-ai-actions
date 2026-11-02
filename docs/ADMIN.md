# The settings chat (admins)

From 0.5.0, admins get the chat bubble on admin pages too (Help Topics, Filters, SLA, Departments, Teams, Lists, Canned Responses, Settings, Logs, Plugins). There it works on the help desk's settings instead of a ticket. The window says **Settings** under the title.

## What it can do

| Area | Create | Change | Disable | Notes |
|---|---|---|---|---|
| Canned responses | ✓ | title, text | ✓ | |
| Help topics | ✓ | name, department, SLA | ✓ | auto-assignment and other options stay |
| List items | ✓ | value | ✓ | in custom lists |
| SLA plans | ✓ | name, grace period | ✓ | |
| Ticket filters | ✓ | name, target, all/any, rules | ✓ | actions: department, priority, help topic, team, SLA, reject; email to fixed addresses (0.5.3) |
| Departments | ✓ (0.5.3) | name, manager, SLA, public | ✓ (undo of a new one) | access of agents: see below |
| Department access | ✓ (0.5.3) | role | – | an agent's access to a department other than their primary one; taken away one agent at a time |
| Teams | ✓ | name, lead | ✓ | members added or removed one at a time |

**Never:** deleting anything (only disabling), agents themselves, roles and permissions, email accounts and passwords, API keys, plugins, system and security settings. Asked for any of that, the assistant says it can't.

### Filters that reject or send email

Both act on **every** new ticket the filter matches, without anyone looking. The assistant says so in its answer, and the preview shows the filter's rules.

- **Reject** stops the ticket from being created, as osTicket's own "Reject Ticket" action.
- **Email** goes to at most five fixed addresses. osTicket variables such as `%{user}` are refused: a filter must not mail whoever wrote the ticket. The sender is osTicket's default; set another one in the filter form afterwards if needed.

## How a change runs

1. You write what you want, e.g. "Create a filter: mail from *@supplier.com goes to Purchasing".
2. The assistant plans it. The preview shows each change with **before → after**.
3. **Run** applies it through osTicket's own model code with its checks. Every change also goes to osTicket's system log ("AI admin change").
4. **Undo** works in the chat for 30 seconds and in the plugin's log (`scp/ajax.php/ai/admin`, tab *Log*) for 24 hours. Something the assistant created is disabled by undo, not deleted. A filter's actions come back exactly as they were before.

## Why departments and teams are handled differently

osTicket's own save for departments and teams rebuilds the member list from the form's `members[]`. A save without the complete list removes everyone who isn't in it. The assistant therefore never uses that path for an existing department or team: it sets only the fields you named on the record and changes memberships one agent at a time, on the membership record itself. A new department goes through osTicket's form code once, when it has no members to lose. An agent's primary department is changed in the agent's settings, not here.

## Safety

- Admins only, as the same pages in osTicket's admin panel: everyone else gets a 404 for the settings conversation, and every change checks again that the agent who clicks **Run** is an admin.
- No ticket content in this conversation (ticket text is where prompt injection comes from). Names in the settings are treated as data.
- Values are checked again when the plan runs: IDs must still exist, the admin must still be an admin.
