After more than two decades of reusing the same pieces of code, images, and shared resources across countless projects, it’s finally time to consolidate everything into a single, reusable repository.
The goal is simple: centralize common components so improvements, fixes, and updates propagate quickly and consistently.

Many of these snippets haven’t been touched in years and show their age. Bringing them together here should make it easier to modernize, clean up, and evolve them over time.

This repository is intentionally designed for cross-project, cross-platform “dirty includes”—small, drop-in files that solve specific problems without needing a full framework.
Because these snippets span many environments and coding styles, certain challenges are expected, and different approaches may coexist.

## first structure proposal to keep things flexible and allows future expansion without breaking existing workflows

- `snip/`
  - `3rd/`    - third party (vendor) items locally stored to maintain compatiblity
  - `c/`      - Temp folder for direct access of cached data (in .gitignore)
  - `Core/`   – Core `.inc.php` files, legacy snippets, shared utilities
  - `Html/`   – Output-visible components, templates, UI helpers
  - `Net/`    – Network logic, API helpers, inter-service utilities
  - `Assets/` – Images, icons, and other shared static files
  - `Misc/`   – Unsorted or experimental snippets pending classification

(to be extended)
