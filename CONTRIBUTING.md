# Contributing

Socly’s public repository is a **source-available** distribution of the base product.

## How to help

- Open an issue for bugs or clear improvement ideas
- Pull requests are reviewed case by case and accepted only under a separate written agreement with the copyright holder
- Do not submit secrets, production data, or personal information

## Development notes

1. Core features belong in `app/`. Optional features belong in `plugins/*.php`.
2. Add UI strings to `lang/it/messages.php`, then mirror `de` and `en`.
3. Schema changes go in `database/migrations/` as sequential `.sql` files.
4. Prefer services for business logic; keep controllers thin.
5. Mutating operations should write to the audit log.
6. Use `Socly\Support\Permission` constants — do not invent ad-hoc permission strings.
7. Never log passwords, fiscal codes, or full request/session dumps.

See [PLUGIN_API.md](docs/PLUGIN_API.md) for drop-in plugins.

## Commercial work

For paid installation, configuration, support, or custom modules, contact [socly.it](https://socly.it).
