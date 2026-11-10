# Contributing

Socly’s public repository is a **source-available** distribution of the base product.

## How to help

- Open an issue for bugs or clear improvement ideas
- Pull requests are reviewed case by case and accepted only under a separate written agreement with the copyright holder
- Do not submit secrets, production data, or personal information

## Development notes

Public documentation (`README.md`, `docs/`, `CHANGELOG.md`) is written in **English only**: no Italian
sections or bilingual accordions in this repository.

Commit messages are written in **English** and follow the `type(scope): summary` convention
(`feat`, `fix`, `perf`, `refactor`, `docs`, `chore`, `ci`): they are the source material for the public
`CHANGELOG.md`, which must gain an entry for every published version.

1. Core features belong in `app/`. Optional features belong in `plugins/*.php`.
2. Add UI strings to `lang/it/messages.php`, then mirror `de` and `en`.
3. Schema changes go in `database/migrations/` as sequential `.sql` files.
4. Prefer services for business logic; keep controllers thin.
5. Mutating operations should write to the audit log.
6. Use `Socly\Support\Permission` constants — do not invent ad-hoc permission strings.
7. Never log passwords, fiscal codes, or full request/session dumps.
8. Document/OCR extraction changes must be tested on a multi-page scanned PDF (page coverage and
   `complete` flag), not only on the first page.

See [PLUGIN_API.md](docs/PLUGIN_API.md) for drop-in plugins.

## Commercial work

For paid installation, configuration, support, or custom modules, contact [socly.it](https://socly.it).
