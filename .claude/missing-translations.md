In file `translations-missing.json` there is JSON object with keys as missing translations and then nested `filled` / `missing`.

In the `missing` there is list of locales where the translations are missing and in the `filled` there is another key val object, where key is locale and value is the translation that exists.

Go through the missing translations and add them to all missing translations.

You can use parallel agents for each locale respectively.

After you are done, run checks to make sure the structure of yaml translations is correct.
