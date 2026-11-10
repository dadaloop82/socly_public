# Photo placeholders

Single source of truth for the grey member silhouettes: the photo field, the membership card
preview and the member detail page all load these files (no per-page re-drawings).

All three files share the same 64×64 canvas, palette (`#dcdfe4` background, `#8b919a`
body, `#757d89` hair) and geometry, so the set looks consistent and can be used as
`background-image` layers (see `.photo-ph-layer` in `public/assets/css/app.css`).

| File | Subject | Source | License |
| --- | --- | --- | --- |
| `placeholder-male.svg` | Short-hair bust | Original artwork (Socly) | Project license |
| `placeholder-female.svg` | Shoulder-length-hair bust | Original artwork (Socly) | Project license |
| `placeholder-neutral.svg` | Plain bust (unknown gender) | Original artwork (Socly) | Project license |

Artwork is inline, hand-authored SVG: no editor metadata, no external fonts, no strokes.
Keep the artwork centered horizontally and anchored to the bottom edge, because the layers
use `background-size: cover` + `background-position: center bottom` (square and portrait
containers, e.g. the membership-card preview).

