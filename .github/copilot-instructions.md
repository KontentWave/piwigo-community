## Morph / WarpGrep usage

For codebase exploration, planning, refactors, or unfamiliar areas, use Morph MCP `codebase_search` first.

When using Morph MCP / WarpGrep:
- Minimize Morph calls: run one focused `codebase_search` first, then read only relevant files.
- Do not create todo lists or progress narration unless explicitly requested.
- Return final answers only: relevant files, line ranges, and one short reason per file.
- After each cross-plugin inspection, update `docs/plugin_integration_map.md` with discovered hooks, shared tables, config keys, dependencies, and risky couplings.
- Treat `docs/plugin_integration_map.md` as a living cross-plugin map: update or rewrite relevant sections after each cross-plugin inspection, avoid duplicate stale findings, and move important historical decisions into ADRs when needed.