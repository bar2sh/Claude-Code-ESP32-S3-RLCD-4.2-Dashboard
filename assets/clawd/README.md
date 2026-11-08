# Clawd animations (data)

The SVG files in this folder are copied unchanged from
[marciogranzotto/clawd-tank](https://github.com/marciogranzotto/clawd-tank), folder `assets/svg-animations/`,
commit `ee5c079c45a39e06e79b7a39f3e6d9beca223021` (2026-10-27). They are covered by the MIT licence in
`LICENSE` next to them: Copyright (c) 2026 Marcio Granzotto Rodrigues.

They are data, not code: nothing in the app or on the board reads them. `node tools/clawd2anim.mjs` samples
them in headless Chrome at fixed moments and writes the black-and-white frames into `server/lib/anim.js`
(docs/SPEC.md, "Animacje").
