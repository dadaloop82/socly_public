# Contributing to Socly

Socly is proprietary software. External contributions are accepted only under a separate written agreement with the copyright holder.

## Development workflow

1. Keep `_baseProject/` read-only and **out of git** (listed in `.gitignore`) — never copy cineforum branding into core.
2. Read [LESSONS_LEARNED.md](LESSONS_LEARNED.md) before adding auth, validation, or destructive ops.
3. Core features belong in `app/`. Optional features belong in `plugins/*.php`.
4. Add UI strings to `lang/it/messages.php`, then mirror `de` and `en`.
5. Schema changes go in `database/migrations/` as sequential `.sql` files.
6. Prefer services for business logic; keep controllers thin.
7. Mutating operations should write to the audit log.
8. Use `Socly\Support\Permission` constants — do not invent ad-hoc permission strings.
9. Never log passwords, fiscal codes, or full request/session dumps.

## Local run

```bash
composer install
# Point Apache/Nginx document root to public/
# or: php -S 127.0.0.1:8080 -t public public/router.php
# Complete /install in the browser
```

## Plugin development

See [PLUGIN_API.md](PLUGIN_API.md). Drop a single PHP file into `plugins/` and enable it from the admin UI.
