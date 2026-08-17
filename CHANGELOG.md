# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-08-17

First public release. TeamWiki provides a group-based team wiki with categories,
entries and per-group visibility, managed entirely from the Admin CP.

### Added
- Category management (add, edit, delete) in the ACP under Configuration → TeamWiki.
- Entry management (add, edit, delete) with a MyCode editor for the content.
- Per-group visibility: categories and entries can be restricted to selected
  usergroups. Entries without their own group mapping inherit the category's groups.
- Front end at `teamwiki.php` with a category/entry navigation menu that only lists
  the entries the current user is allowed to see.
- English and German (du) language files for both front end and ACP.
- Own stylesheet (`teamwiki.css`) registered across all themes on activation.

### Fixed
- **Templates were never installed.** The template loader scanned
  `inc/plugins/teamwiki/` instead of `inc/plugins/teamwiki/templates/`, so no `.tpl`
  file was written to the database on activation and the front end rendered empty.
  The loader now points at the `templates/` subdirectory.
- **Duplicate stylesheet rows on re-activation.** `teamwiki_activate()` inserted the
  `teamwiki.css` themestylesheet unconditionally; re-activating created duplicates.
  It now updates an existing row and only inserts when none exists.
- **Deleting a category orphaned its data.** Category deletion now also removes the
  category's entries and their group mappings; entry deletion removes the entry's
  group mappings.
- **Empty admin action log on category delete.** The log read the non-existent
  `title` column of the categories table (which uses `name`); fixed to `name`.
- Missing ACP language strings (`teamwiki_manage_error_no_description`,
  `teamwiki_manage_error_no_rid`, `teamwiki_manage_entry_title_desc`) added, and the
  wrong flash key `teamwiki_manage_event_added` corrected to `teamwiki_manage_entry_added`.

### Security
- All add/edit/delete actions now run over POST and validate `verify_post_check()`
  (CSRF protection); previously only the delete actions were protected.
- All request data is read through `$mybb->get_input()` with explicit types instead
  of direct `$mybb->input[...]` access; IDs are integer-cast in every WHERE clause.

[1.0.0]: https://github.com/pand0rica/teamwiki/releases/tag/v1.0.0
