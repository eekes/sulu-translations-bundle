# Sulu Translations Bundle

Makes the translations of a Sulu 3 project editable in the Sulu admin, in every language, by the
people who write the texts. A saved value is live on the next request: no `translation:pull`, no
file rewrite, no deploy and no `cache:clear`.

## What's inside

- [**The admin interface**](https://sulu.eekes.be/sulu-translations-bundle/admin-interface): one row per key with inline editing, a reference-language column and a filter for what is still untranslated.
- [**Placeholders and ICU messages**](https://sulu.eekes.be/sulu-translations-bundle/placeholders-and-icu): a save that drops a placeholder is refused, and ICU messages get a plural editor.
- [**Machine translation**](https://sulu.eekes.be/sulu-translations-bundle/machine-translation): DeepL, or a service of your own, for what is still empty, with placeholders protected.
- [**Key discovery**](https://sulu.eekes.be/sulu-translations-bundle/key-discovery): keys your templates and code ask for show up in the admin by themselves.
- [**Migrations and deployment**](https://sulu.eekes.be/sulu-translations-bundle/migrations-and-deployment): a release carries its translations as a Doctrine migration.
- [**Import and export**](https://sulu.eekes.be/sulu-translations-bundle/import-export): bring in the `translations/*.yaml` you already have, and export back to files.
- [**History and activity**](https://sulu.eekes.be/sulu-translations-bundle/history-and-activity): every value keeps its previous versions, and every change lands in Sulu's activity trail.
- [**Permissions**](https://sulu.eekes.be/sulu-translations-bundle/permissions): per language and per domain, on Sulu's own roles.

## Documentation

[Installation](https://sulu.eekes.be/sulu-translations-bundle/installation), [configuration](https://sulu.eekes.be/sulu-translations-bundle/configuration) and everything else: **[sulu.eekes.be/sulu-translations-bundle](https://sulu.eekes.be/sulu-translations-bundle)**.

## License

MIT
