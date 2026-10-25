# inclform – Include Form plugin for DokuWiki

Includes an approved PHP form (or any other PHP snippet) from a trusted directory into a wiki page.

Documentation: <https://www.dokuwiki.org/plugin:inclform>

This plugin is a fork of the [incl_form](https://www.dokuwiki.org/plugin:incl_form) plugin by Kite (kite@puzzlers.org), which in turn is based on the "externallink" plugin by Otto Vainio. Version 2 is a rewrite for current DokuWiki and PHP versions.

## Usage

```
~~INCLFORM~~                       form that belongs to the current page
~~INCLFORM=my:form~~               form "my:form"
~~INCLFORM=my:form;a=1;b=two~~     form "my:form" with the parameters a and b
```

The ID `my:form` is mapped to `<formdir>/my/form.php`. The directory (`formdir`) is set in the configuration manager. The default is `data/forms`, relative to the DokuWiki root; an absolute path works as well.

Example form `data/forms/greeting.php`:

```php
<p>Hello <?php echo $FORM_PARAMS['name']; ?>!</p>
```

Page: `~~INCLFORM=greeting;name=World~~`

### Variables available in a form

| Variable            | Content                                                                     |
|---------------------|-----------------------------------------------------------------------------|
| `$FORM_PARAMS`      | Parameters from the page, **HTML-escaped**. Safe to print.                  |
| `$FORM_PARAMS_RAW`  | The same parameters, unmodified. Validate and escape them yourself.         |

DokuWiki's globals (`$ID`, `$INFO`, `$conf`, ...) can be used with the usual `global` statement.

## Security

The forms are PHP code that is executed by the web server. Therefore:

- Only trusted people may be able to write into the form directory.
- The form directory should not be reachable through the web server. The default `data/forms` is protected by the `.htaccess` file that DokuWiki ships in `data/`; with other web servers you have to configure this yourself, or use an absolute path outside of the document root.
- Wiki editors only control the form *name* and the *parameters*. The name is reduced to a wiki ID, and the resulting file must be located inside the form directory (symlinks pointing out of it are refused). Parameters are escaped by default.
- Pages containing the tag are never cached, because forms are dynamic.
- Errors in a form never break the page. Only administrators see a message; visitors see nothing. Details are written to DokuWiki's error log.

## Upgrading from version 1

Version 2 does not keep backward compatibility with the old internals. If you have forms, check them for:

1. `global $FORM_PARAMS;` no longer works. `$FORM_PARAMS` is now a local variable of the form.
2. Its values are HTML-escaped now. Use `$FORM_PARAMS_RAW` if you need the original text.
3. The form name is not part of `$FORM_PARAMS` anymore (it was a key without a value before, which caused PHP warnings).
4. A form runs in its own variable scope. Variables of one form are not visible in another form, and the plugin's variables are not visible in the form.
5. A missing form no longer produces an HTML comment, administrators get a message instead.
6. Pages with the tag are not cached any more (previously the output of the first view was served to everybody).

## Requirements

Tested with the current DokuWiki development version and PHP 8.3. The plugin needs a DokuWiki that provides `\dokuwiki\Extension\SyntaxPlugin` and `\dokuwiki\Logger`; older releases have not been tested.

## Tests

The plugin ships with tests for DokuWiki's test framework. Place the plugin in `lib/plugins/inclform` of a DokuWiki checkout, install the test dependencies (`composer install` in `_test/`), and run:

```
cd _test
vendor/bin/phpunit --group plugin_inclform
```

## License

GPL 2, see `LICENSE`.
