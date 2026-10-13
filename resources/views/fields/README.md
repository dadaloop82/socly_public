# SOCLY field modules

Every user-facing control in SOCLY must go through `field()`.

```php
<?= field('email', [
    'name' => 'fields[email]',
    'label' => __('members.email'),
    'value' => $value,
    'required' => true,
    'context' => 'member', // member | setup | filter | inline | default
    'icon' => 'at',
    'attrs' => ['data-email-input' => true],
]) ?>
```

## Types

| Type | Alias | Notes |
|------|-------|--------|
| `text` | | Generic text |
| `email` | | Validates + emphasis |
| `number` | | `min`/`max`/`step` |
| `search` | | Filter bars |
| `color` | | Brand pickers |
| `date` | | Plain date |
| `birth_date` | `birth` | Date + age output |
| `textarea` | | |
| `select` | | `options`, `groups`, `trailing_options` |
| `checkbox` | `check` | |
| `radio` | | Simple list |
| `radio_cards` | `choice` | Tessera/setup cards |
| `phone` | `tel` | Dial + national |
| `password` | `pwd` | Show/hide |
| `file` | | Button or native |
| `hidden` | | |
| `gender` | | M/F(+X) |
| `language` | `lang` | it/de/en/other |
| `fiscal_code` | `cf` | + generate button |
| `birth_place` | `geo_birth` | Suggest + estero |
| `address` | `geo_address` | City/CAP/via/civico |
| `photo` | | Upload/camera/remove |
| `locale_cards` | `locale` | Flag radio cards |
| `control` | | Bare `<input>` for custom chrome |

## Bare mode

- `field('checkbox', ['bare' => true, ...])` → only the input (component cards).
- `field('textarea', ['bare' => true, ...])` → only the textarea (legal editor tabs).
- `field('control', ['input_type' => 'radio', ...])` → bare input of any type.

## Rules

1. Do **not** add raw `<input>` / `<select>` / `<textarea>` in views — call `field()`.
2. Preserve JS hooks with `attrs` (`data-*`).
3. Contextual classes via `context` + `wrapper_class` (never fork a parallel control).
4. Composites (`legal_doc_editor`, `member_fields_editor`, enrollment picker) may own layout chrome, but their atomic controls must still call `field()`.
