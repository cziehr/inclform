<?php

/**
 * DokuWiki Plugin inclform (Syntax Component)
 *
 * Includes an approved PHP form (or any other PHP snippet) from a trusted
 * directory into a wiki page:
 *
 *     ~~INCLFORM~~                      include the form named like the current page
 *     ~~INCLFORM=my:form~~              include the form "my:form"
 *     ~~INCLFORM=my:form;a=1;b=two~~    ... and hand over the parameters a and b
 *
 * A wiki ID like "my:form" is mapped to "<formdir>/my/form.php".
 *
 * SECURITY MODEL
 * --------------
 * The included files are executed as PHP code. That is the whole purpose of
 * this plugin, so the form directory MUST be writable by trusted people only
 * and SHOULD NOT be reachable through the web server (the default
 * "data/forms" is protected by the .htaccess shipped in "data/").
 * Everything that is controlled by wiki editors (the form name and the
 * parameters) is treated as untrusted input:
 *
 *  - the form name is reduced to a valid wiki ID and the resulting path is
 *    verified to stay inside the form directory (no path traversal),
 *  - parameter values are HTML-escaped before they are handed to the form,
 *  - the output is never cached, because forms are dynamic by nature.
 *
 * History: based on the "incl_form" plugin by Kite, which in turn is based on
 * the "externallink" plugin by Otto Vainio. Rewritten for current DokuWiki and
 * PHP versions (no more eval(), no globals, no PHP warnings).
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author  Kite <Kite@puzzlers.org>
 * @author  cziehr <info@einsatzleiterwiki.de>
 */

use dokuwiki\Extension\SyntaxPlugin;
use dokuwiki\Logger;

// must be run within DokuWiki
if (!defined('DOKU_INC')) {
    die();
}

class syntax_plugin_inclform extends SyntaxPlugin
{
    /**
     * Syntax type.
     *
     * NOTE: 'substition' is NOT a typo of ours. It is the (misspelled) name
     * DokuWiki itself uses for this syntax type, so it has to stay as is.
     *
     * @return string
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * Sort order. 299 places us just before the built-in link handling (300).
     *
     * @return int
     */
    public function getSort()
    {
        return 299;
    }

    /**
     * Paragraph type. A form is rendered as a block element, therefore
     * DokuWiki must not wrap it into a <p> element.
     *
     * @return string
     */
    public function getPType()
    {
        return 'block';
    }

    /**
     * Register the syntax pattern with the lexer.
     *
     * The tag is either "~~INCLFORM~~" or "~~INCLFORM=<specification>~~".
     * The specification cannot contain a tilde (that would end the tag).
     * Only a non-capturing group may be used here, the lexer does not
     * support capturing groups in patterns.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~INCLFORM(?:=[^~]*)?~~', $mode, 'plugin_inclform');
    }

    /**
     * Parse the matched tag into a form ID and a parameter list.
     *
     * This runs once per page change; the result is stored in DokuWiki's
     * instruction cache. Therefore it must not depend on anything but the
     * matched text.
     *
     * Specification syntax: "<form>;<key>=<value>;<key>=<value>..."
     *  - the form name is optional, an empty name means "the current page"
     *  - a parameter without "=" is accepted and gets an empty value
     *  - only the first "=" separates key and value, so values may contain "="
     *  - parameters without a key are ignored (e.g. caused by ";;")
     *
     * @param string       $match   The text matched by the pattern
     * @param int          $state   The lexer state
     * @param int          $pos     Byte position of the match in the page
     * @param Doku_Handler $handler The handler
     * @return array{form: string, params: array<string,string>}
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        // strip the leading "~~INCLFORM" and the trailing "~~"
        $spec = substr($match, strlen('~~INCLFORM'), -2);
        // what remains is either empty or "=<specification>"
        $spec = (string)substr($spec, 1);

        $items = explode(';', $spec);

        // The first item is the form name. Turn it into a valid wiki ID right
        // away: this removes anything that could be abused in a file path.
        $form = cleanID((string)array_shift($items));

        $params = [];
        foreach ($items as $item) {
            // limit 2: everything after the first "=" belongs to the value
            $parts = explode('=', $item, 2);
            $key = trim($parts[0]);
            if ($key === '') {
                continue;
            }
            $params[$key] = isset($parts[1]) ? $parts[1] : '';
        }

        return ['form' => $form, 'params' => $params];
    }

    /**
     * Render the included form.
     *
     * Only the XHTML output is supported. Other formats (metadata, plain
     * text export, ...) intentionally get nothing, because running arbitrary
     * form code outside of a normal page view makes no sense.
     *
     * @param string        $format   Renderer format
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data returned by handle()
     * @return bool true if the format was handled
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        if ($format !== 'xhtml') {
            return false;
        }

        // Forms are dynamic (session data, tokens, current time, ...). If
        // their output ended up in DokuWiki's page cache, it would be served
        // to other users and other requests. So never cache pages using us.
        $renderer->nocache();

        $renderer->doc .= $this->includeForm($data['form'], $data['params']);
        return true;
    }

    /**
     * Locate, run and return the output of a form.
     *
     * Errors never break the page: a missing form or an exception inside a
     * form results in empty output. Administrators additionally get a
     * message, everybody else must not learn about server internals.
     *
     * @param string               $form   Cleaned form ID, empty for the current page
     * @param array<string,string> $params Raw parameters from the wiki page
     * @return string The HTML produced by the form
     */
    protected function includeForm($form, array $params)
    {
        global $ID;

        if ($form === '') {
            // default: the form that belongs to the current page
            $form = cleanID((string)$ID);
        }

        $file = $this->resolveFormFile($form);
        if ($file === null) {
            $this->reportProblem(sprintf($this->getLang('err_not_found'), hsc($form)));
            return '';
        }

        try {
            return $this->runForm($file, $params);
        } catch (\Throwable $e) {
            // \Throwable also catches PHP engine errors (TypeError, ParseError, ...)
            Logger::error(
                'inclform: form "' . $form . '" failed: ' . $e->getMessage(),
                (string)$e,
                $e->getFile(),
                $e->getLine()
            );
            $this->reportProblem(sprintf($this->getLang('err_failed'), hsc($form), hsc($e->getMessage())));
            return '';
        }
    }

    /**
     * Execute a form file and capture its output.
     *
     * The file is loaded with a plain include inside a static closure.
     * Compared to the former eval() this has several advantages:
     *  - the file is seen by the PHP engine as a real file: correct file names
     *    and line numbers in errors, OPcache and static analysis work,
     *  - the form runs in its own variable scope. It cannot see (and
     *    accidentally change) our local variables, and because the closure is
     *    static there is no $this either,
     *  - parameters are passed explicitly instead of through a global variable,
     *    so several forms on one page cannot influence each other.
     *
     * Variables available to the form:
     *  - $FORM_PARAMS     parameters from the wiki page, HTML-escaped (safe to print)
     *  - $FORM_PARAMS_RAW the same parameters unmodified. Escape them yourself
     *                     before printing and never use them in file paths,
     *                     shell commands or SQL without proper validation.
     * DokuWiki globals ($ID, $INFO, $conf, ...) are available with the usual
     * "global" statement.
     *
     * @param string               $file   Absolute path, already verified by resolveFormFile()
     * @param array<string,string> $params Raw parameters
     * @return string
     * @throws \Throwable whatever the form throws
     */
    protected function runForm($file, array $params)
    {
        $run = static function ($__inclformFile, $FORM_PARAMS, $FORM_PARAMS_RAW) {
            $level = ob_get_level();
            ob_start();
            try {
                include $__inclformFile;
                return (string)ob_get_clean();
            } catch (\Throwable $e) {
                // Drop everything the form (and anything it opened) buffered,
                // otherwise a half-finished form would leak into the page and
                // the output buffer stack would be left unbalanced.
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
                throw $e;
            }
        };

        return $run($file, array_map('hsc', $params), $params);
    }

    /**
     * Map a form ID to a file and make sure it is safe to execute.
     *
     * The ID "a:b:c" becomes "<formdir>/a/b/c.php". Even though the ID has
     * been cleaned already, the final path is resolved (symlinks and ".."
     * included) and must be located inside the form directory. This is the
     * actual protection against path traversal; the cleaning is only the first
     * line of defense.
     *
     * @param string $id Cleaned form ID
     * @return string|null Absolute path of the form file or null if there is none
     */
    protected function resolveFormFile($id)
    {
        $base = $this->getFormDirectory();
        if ($base === null || $id === '') {
            return null;
        }

        // ID -> relative path. utf8_encodeFN() applies the file name encoding
        // configured in DokuWiki ('fnencode'), just like for page files.
        $relative = utf8_encodeFN(str_replace(':', '/', $id));

        $file = realpath($base . '/' . $relative . '.php');
        if ($file === false || !is_file($file)) {
            return null;
        }

        // the resolved file must live below the form directory
        $prefix = rtrim($base, '/\\') . DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') {
            // Windows file names are case insensitive
            $inside = stripos($file, $prefix) === 0;
        } else {
            $inside = strpos($file, $prefix) === 0;
        }

        return $inside ? $file : null;
    }

    /**
     * Get the resolved, absolute path of the configured form directory.
     *
     * The "formdir" option is relative to the DokuWiki root (this was the only
     * supported form in earlier versions). Absolute paths are accepted as well,
     * so the forms can be stored outside of the DokuWiki directory.
     *
     * @return string|null null if the directory is not configured or does not exist
     */
    protected function getFormDirectory()
    {
        $dir = trim((string)$this->getConf('formdir'));
        if ($dir === '') {
            return null;
        }

        // absolute: "/srv/forms", "\forms", "C:\forms", "C:/forms"
        if (!preg_match('#^([a-zA-Z]:)?[/\\\\]#', $dir)) {
            $dir = DOKU_INC . $dir;
        }

        $real = realpath($dir);
        return ($real !== false && is_dir($real)) ? $real : null;
    }

    /**
     * Tell the administrator that something went wrong.
     *
     * Regular visitors see nothing at all. (A page that is allowed to show
     * error details to everybody would hand out file names and PHP messages to
     * anonymous users.)
     *
     * @param string $message Already HTML-escaped message
     */
    protected function reportProblem($message)
    {
        global $INFO;

        if (!empty($INFO['isadmin'])) {
            msg('inclform: ' . $message, -1);
        }
    }
}
