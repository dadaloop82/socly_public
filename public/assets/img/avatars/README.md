# Photo placeholders

Single source of truth for the grey member silhouettes: the photo field, the membership card
preview and the member detail page all load these files (no per-page re-drawings).

Both files share the same 64×64 canvas, palette (`#e7eaee` background, `#9aa2ad`
body, `#79818d` hair) and bust geometry, so the pair looks consistent and can be used as
`background-image` layers (see `.photo-ph-layer` in `public/assets/css/app.css`).

| File | Subject | Source | License |
| --- | --- | --- | --- |
| `placeholder-male.svg` | Short-hair bust | Original artwork (Socly) | Project license |
| `placeholder-female.svg` | Long-hair bust | Original artwork (Socly) | Project license |

These are the **only two** placeholders in the product: the member detail page, the photo field and
the membership-card preview all load these files (unknown gender falls back to the male artwork).
Never add a third silhouette nor inline a per-page copy.

Artwork is inline, hand-authored SVG: no editor metadata, no external fonts, no strokes.
Keep the artwork centered horizontally and anchored to the bottom edge, because the layers
use `background-size: cover` + `background-position: center bottom` (square and portrait
containers, e.g. the membership-card preview).

