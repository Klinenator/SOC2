# Git hooks

Version-controlled hooks for this repo. Enabled via `core.hooksPath` (not copied by
`git clone`, so **each clone must opt in once**):

```sh
git config core.hooksPath .githooks
```

## `pre-push` — CC8.1 change-management reminder

Pushing this repo deploys to production (the server `git pull`s from CodeCommit), so
every push is a production change that should have a change-request ticket:
<https://tools.rrsaccess.com/Tickets/>.

- **Interactive terminal:** asks whether a ticket has been filed; answering `n` (or
  empty) aborts the push so you can file one first.
- **No terminal** (agent / CI): prints the reminder and continues — never blocks
  automation.
- **Bypass:** `SKIP_TICKET_CHECK=1 git push`.

The hook only reminds/gates; it never modifies anything.
