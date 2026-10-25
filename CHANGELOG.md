# Changelog

## 2.0.0 – 2026-10-25

Rewrite for current DokuWiki and PHP versions. **Breaking changes**, see "Upgrading" in the README.

### Security
- Replaced `eval()` by a plain `include` inside an isolated static closure.
- Path traversal protection: the final file path is resolved with `realpath()` and has to stay inside the form directory.
- Parameter values are HTML-escaped by default (`$FORM_PARAMS`); the original values are available as `$FORM_PARAMS_RAW`.
- Pages with the tag are never cached (`$renderer->nocache()`).
- No unescaped user input in the output any more (the error comment used the raw form name).
- Error details are only shown to administrators and are logged with `\dokuwiki\Logger`.

### Fixed
- `Warning: Undefined array key 1` in `syntax.php` on PHP 8 (parameters without `=`).
- Parameter values containing `=` were cut off.
- Parameters leaked from one form to the next one on the same page (global `$FORM_PARAMS`).
- Output buffers are balanced again if a form throws an exception.

### Changed
- Uses `\dokuwiki\Extension\SyntaxPlugin`; removed `require_once` of the old base class and the `DOKU_INC`/`DOKU_PLUGIN` definitions.
- Form directory may be an absolute path.
- Parsing of the tag moved from `render()` to `handle()` so that it is part of DokuWiki's instruction cache.
- Strictly matching lexer pattern: `~~INCLFORM~~` or `~~INCLFORM=...~~`.
- Language files: license header corrected to GPL 2 (matches `LICENSE`), added `lang.php` with the admin messages.

### Added
- Tests for DokuWiki's test framework (`_test/`).
- `CHANGELOG.md`; rewritten `README.md` with security notes.
