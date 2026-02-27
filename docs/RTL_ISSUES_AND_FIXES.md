# RTL/LTR Issues and Fixes

## Fixed in this iteration

1. Header profile spacing used hardcoded `margin-right`, `border-right`, `padding-right`
- Fix: moved to logical properties (`margin-inline-start`, `border-inline-start`, `padding-inline-start`) in `partials/unified-header.php`.

2. Header badge position used hardcoded `top/right`
- Fix: switched to logical inset (`inset-block-start`, `inset-inline-end`) in `partials/unified-header.php`.

3. Toast border direction was static (`border-right`)
- Fix: switched to `border-inline-start` in `public/js/main.js`.

4. Search icon / clear button positions broke in LTR
- Fix: added directional overrides for `.search-icon`, `.clear-search`, `.search-input` in `public/css/themes.css`.

5. Side panel borders were static for RTL
- Fix: added LTR border overrides for `.sidebar`, `.timeline-panel`, `.timeline-sidebar` in `public/css/themes.css`.

6. No per-component direction override pattern
- Fix: added `data-dir-override="auto|rtl|ltr"` handling in `public/js/direction.js`.

## Remaining areas (tracked)

1. Some legacy views still contain inline left/right values.
2. Some absolute-positioned controls in old templates require per-screen cleanup.
3. Mixed inline styles in `index.php` and legacy views should be migrated to logical CSS properties gradually.
