# Sulu Translations Bundle

Makes a Sulu 3 project's translations editable in the Sulu admin, in every language, by the
people who write the texts. A saved value is live on the next request.

No `translation:pull`, no file rewrite, no deploy, no `cache:clear`.

- **Every string in the admin** — one row per key, per language, with Sulu's language
  switcher, inline editing, a reference-language column and a filter for what is still
  untranslated.
- **Placeholders are checked.** `%name%`, `{{ name }}` and `{count}` are stored on the key;
  the editor gets a clickable chip per placeholder and a save that drops one is refused.
- **ICU messages** get a plural editor instead of a text box, with the syntax validated on
  save.
- **DeepL machine translation** for what is still empty, with placeholders protected, as a
  bulk run or a per-value suggestion you accept or discard.
- **Bulk actions**: search and replace, copy from another language, translate what is missing
  — each showing a count before it commits.
- **History and activity.** Every value keeps its previous versions with a restore, and every
  change lands in Sulu's activity trail.
- **Migrations.** A release carries its translations as a generated Doctrine migration, so a
  deploy is `doctrine:migrations:migrate` and nothing else.
- **Import** of the `translations/*.yaml` you already have — in the admin with a diff preview
  first, or on the CLI — plus export back to files, key discovery from your code, and orphan
  and unused reporting.
- **Per-language and per-domain permissions**, on Sulu's own role system.

## Installation

**1. Install the bundle**:

```bash
composer require eekes/sulu-translations-bundle
```

**2. Register it** in `config/bundles.php`:

```php
Eekes\SuluTranslationsBundle\SuluTranslationsBundle::class => ['all' => true],
```

**3. Import the admin API routes.** The one line of YAML the bundle cannot prepend for you —
Symfony has no hook for a bundle to register routes. In `config/routes/sulu_admin.yaml`:

```yaml
eekes_sulu_translations_api:
    resource: "@SuluTranslationsBundle/config/routing_admin_api.yaml"
    prefix: /admin/api
```

**4. Create the tables.** They are ordinary Doctrine entities, so your own migrations pick
them up:

```bash
bin/console doctrine:migrations:diff
bin/console doctrine:migrations:migrate
```

**5. Add the admin JavaScript.** In `assets/admin/package.json`:

```json
"sulu-translations-bundle": "file:../../vendor/eekes/sulu-translations-bundle/assets/js"
```

and in `assets/admin/app.js`:

```js
import 'sulu-translations-bundle';
```

Then rebuild the admin:

```bash
bin/adminconsole sulu:admin:update-build
```

**6. Give yourself permission** under *Settings → User roles*. There are two contexts:

- `sulu.settings.translations` — view / add / edit / delete the translations themselves;
- `sulu.settings.translations_admin` — may also see and change the developer-facing half of a
  key: the **Context** panel, the **ICU** switch and the **protected** switch.

The screen is at *Settings → Translations*.

## Getting your translations in

### A new project

Write `trans()` calls as you always would and do not create translation files at all:

```twig
{{ 'checkout.total'|trans }}
```

Nothing can answer the key, so the bundle records it and it shows up in the admin waiting to
be filled in. `eekes:translations:scan` does the same for every key in `templates/` and `src/`
at once, without visiting the pages.

### A project that already has translation files

```bash
bin/console eekes:translations:generate-migration --from=files
```

This reads every translation file Symfony's reader supports and writes a Doctrine migration
full of guarded `INSERT`s. Commit it, and every environment gets the same translations on its
next `doctrine:migrations:migrate`.

It reports what it found — keys, value rows, languages, what it left alone — and asks before it
writes the file. `--dry-run` stops after the report; `--no-interaction` answers yes, so a script
is unaffected.

### Shipping new translations with a release

Everything happens on your own machine, before you push:

```bash
# 1. make sure every new key exists locally
bin/console eekes:translations:scan

# 2. fill them in, in the admin or with the machine translator
bin/console eekes:translations:translate --to=nl

# 3. turn exactly those rows into the next migration
bin/console eekes:translations:generate-migration --from=database

# 4. run it here too - that is what marks the rows as shipped - and commit the migration
bin/console doctrine:migrations:migrate
```

The deploy then runs `doctrine:migrations:migrate` and nothing else.

Worth knowing about step 3:

- **It carries a delta**, only the values no migration has taken along yet. `--all` overrides
  that.
- **Running the migration marks the rows**, not generating it. Generating costs nothing and
  can be repeated.
- **It will not overwrite an editor.** A value changed after it was shipped is listed and
  skipped; `--include-changed` carries it anyway.
- **It is safe to run twice**, on any environment, in any order.

### Removing a key everywhere

Deleting is the one thing a release does not carry by itself: `prune` empties your own
database, and every other environment keeps the key. `--migration` closes that:

```bash
bin/console eekes:translations:prune --force --migration
```

You get the deletions you just made as a Doctrine migration to commit, next to the one that
carries the new translations.

It cannot take a key the target still needs:

- **A key the target has rendered** since it was orphaned here is kept there.
- **Protected keys** are left alone, unless the prune itself ran with `--include-protected`.
- **It is safe to run twice**, and a no-op on the machine that wrote it.
- The values and the version history go with the key; nothing else is touched.

It needs `--force` — without it `prune` only reports, and reports that too.

## On every deploy

```bash
bin/console doctrine:migrations:migrate   # schema, and the translations a release carries
```

That is the whole list. A value an editor saves is live on the next request; a value a release
carries is live as soon as its migration has run.

## The admin

**The list**, at *Settings → Translations*, with Sulu's language switcher:

- one row per key, showing the value in the selected language;
- **inline editing** — click a cell and it opens a textarea floating over the rows below.
  Enter saves, Tab saves and opens the next row (Shift+Tab the previous one), Shift+Enter is a
  newline, Escape cancels;
- a **reference language** column, so a translator has the English next to the French;
- a **Translated** column with Sulu's boolean filter, a domain filter, and a search that
  covers keys *and* values;
- **machine translation**, once a service is configured: a globe on every row of the reference
  column that translates that row and saves it, asking first if that would replace something,
  and **Translate these rows** in the toolbar for the rows you tick, one at a time.

**The form**, with the same language switcher, the reference sentence above the box — with a
globe that translates it into the box unsaved, with an undo — a text area or a plural editor
for ICU messages, the ICU and protected switches, the key's metadata, a **History** tab with
restore, and Sulu's **Activity** tab.

There is deliberately **no review workflow**: a value is translated or it is not, and the
activity trail and the history say who changed it and what it said before.

**Keys appear by themselves.** Anything `trans()` asks for and nothing can answer is recorded
and shows up in the admin. On by default in debug, and supported in production:

```yaml
eekes_sulu_translations:
    discovery:
        collect_missing: always
```

**Placeholders are on the key, and the admin shows them.** The source-language value declares
what a key takes — `%name%`, `{{ name }}`, `{count}` — and that list is stored on the key.
Under the text box, in the form and in the inline editor alike, the editor gets a chip per
placeholder: quiet once it is in their sentence, marked while it is not, and clicking one
pastes it at the cursor. A row whose saved value lost one is marked in the list.

A translation that drops one is refused, and so is a change to the source value that would take
a placeholder away while another language still uses it — that one names the languages to fix
first. Notation does not matter: `%count%` and `{count}` are the same argument.
`validation.placeholders: off` disables the refusals and keeps the chips and the markers.

`eekes:translations:sync-placeholders` fills the list for keys that predate it, and reports the
translations that do not carry them.

**Sulu's own labels are overridable.** Nothing is specific to the `messages` domain, so a row
in the `admin` domain renames a backend label. Put `admin` in `domains.read_only` to prevent
that.

`domains.ignore` defaults to `['admin']`: nothing fills that catalogue by itself — not
discovery, not `import`, `scan` or `generate-migration --from=files`. So the labels Sulu and its
bundles ship without a translation never arrive as work, and a file like
`translations/sulu/admin.nl.json` is not imported either. Adding such a key yourself still
overrides the label, and `--domain=admin` still imports or carries it.

### The Context panel

The panel on the form is filled by several different things:

| Line | Filled by |
|---|---|
| Key, domain | always |
| Origin | whatever created the key: import (`file`), scan (`code`), an unanswered `trans()` (`runtime`), the add form (`manual`) |
| **Used in** | **`eekes:translations:scan` only** — nothing else records file and line |
| **Last shown** | the site rendering the key, with `discovery.track_usage` on |

## How it works

`DatabaseTranslator` decorates the `translator` service and adds the database rows to the
message catalogue and to every catalogue in its fallback chain before the inner translator
resolves anything. Symfony keeps doing fallbacks, plurals, ICU and parameter expansion, and
the database wins over the files.

Every write bumps a counter in a one-row table, and the compiled catalogue is cached under a
key containing it — so the admin and website kernels, which have separate caches, cannot serve
stale text. The cost is one primary-key read per request;
`eekes_sulu_translations.cache.version_ttl` trades it away.

**The translator never fatals.** A missing table, an unreachable database, a re-entrant
`trans()` during catalogue loading and a cache warmup all degrade to serving the file
catalogue.

## Commands

| | |
|---|---|
| `eekes:translations:generate-migration` | translations → a Doctrine migration, so a release carries them. `--from=files` once at install, `--from=database` before every release after that. |
| `eekes:translations:import` | files → database, directly. Never overwrites an edited value unless `--overwrite`. |
| `eekes:translations:export` | database → files. A fallback for a site whose database is down, or XLIFF for an outside translator. |
| `eekes:translations:scan` | extract keys from templates and PHP, record where they occur, `--mark-orphans`. |
| `eekes:translations:unused` | keys neither the extractor nor the site has seen lately. |
| `eekes:translations:prune` | delete keys orphaned for long enough. Reports first; needs `--force`. `--migration` also writes the migration that deletes them everywhere else. |
| `eekes:translations:translate` | fill the empty values of a language with a machine translation. |
| `eekes:translations:status` | how many keys each language has translated. `--fail-on-missing` for a release gate. |
| `eekes:translations:sync-locales` | create the rows a newly added language needs. |
| `eekes:translations:sync-placeholders` | store the placeholders every key declares, and report the translations that do not carry them. |
| `eekes:translations:dump` | snapshot to `var/translations`, so an IDE can resolve your keys. |

All of them take `--dry-run`.

`import` and `generate-migration --from=files` read the same files. `import` writes to the
database it is pointed at, so it has to run on every environment; `generate-migration` writes a
file you commit, and every environment picks it up with the rest of the schema.

## Configuration

Everything has a default that fits a stock Sulu project; a consuming project needs none of it.

```yaml
eekes_sulu_translations:
    source_locale: ~                 # defaults to framework.default_locale
    extra_locales: []                # languages no webspace serves - mail, an app API
    domains:
        read_only: []                # e.g. ['validators', 'admin']
        ignore: ['admin']            # catalogues nothing fills by itself
    cache:
        pool: cache.app
        version_ttl: 0               # seconds; 0 means an edit is live on the next request
    validation:
        placeholders: strict         # strict | off
    versions:
        keep: 20
    discovery:
        collect_missing: auto        # auto | always | never
        limit: 200
        track_usage: true
        usage_throttle: 86400
        scan_paths: ['%kernel.project_dir%/templates', '%kernel.project_dir%/src']
    import:
        paths: ['%kernel.project_dir%/translations']
    export:
        path: '%kernel.project_dir%/translations'
        format: yaml
    dump:
        path: '%kernel.project_dir%/var/translations'
        format: yaml
    permissions:
        per_locale: false            # use Sulu's per-locale role restrictions
    machine_translation:
        enabled: false
        service: ~                   # any service implementing MachineTranslatorInterface
        batch_size: 50
        max_per_run: 500
        deepl:
            api_key: '%env(DEEPL_API_KEY)%'
```

**Machine translation** stays off until `enabled` is true — the key on its own does nothing.
DeepL is what ships, and Free and Pro need no setting of their own: the `:fx` a Free key ends
in picks the host. Anything else — an LLM, an internal service — is four lines against
`MachineTranslatorInterface` and a service id in `machine_translation.service`.

Placeholders are wrapped in tags the engine leaves alone and put back afterwards; a result that
lost one is discarded. The command and the bulk action only fill values that are **empty**, and
the two places that can replace a translation ask first. Machine output is not marked as such.

To see the old and new value in the activity trail, turn on Sulu's payload storage — it is off
in Sulu by default and the bundle does not change it for you:

```yaml
sulu_activity:
    storage:
        persist_payload: true
```

## License

MIT.
