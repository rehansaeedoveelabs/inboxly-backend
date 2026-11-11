# Inboxly backend — working rules

## Git

- **Do not commit or push on `main`.**
- Always work on a feature/fix branch.
- Do not merge to `main` unless the user explicitly asks.
- Do not force-push to `main`.
- Do not commit unless the user explicitly asks to commit.
- Do not push unless the user explicitly asks to push.

## Scope

- Backend repository only for backend tasks.
- Do not modify the frontend repo, local `.env`, secrets, or Shopify credentials unless the user explicitly asks.
- Prefer the smallest change that satisfies the request; avoid unrelated refactors.
- Never reset or wipe the development database; use `inboxly_testing` for tests only.

## Follow this file strictly

Agents and contributors must obey these rules for every change in this repository.
