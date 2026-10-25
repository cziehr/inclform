<?php

namespace dokuwiki\plugin\inclform\test;

use DokuWikiTest;

/**
 * Tests for the syntax component of the inclform plugin
 *
 * @group plugin_inclform
 * @group plugins
 */
class syntax_plugin_inclform_test extends DokuWikiTest
{
    protected $pluginsEnabled = ['inclform'];

    /** @var string absolute path of the temporary form directory */
    protected $formdir;

    /** @var string a file outside of the form directory, used by the traversal tests */
    protected $outsideFile;

    public function setUp(): void
    {
        parent::setUp();
        global $conf;

        // every test gets a fresh, empty form directory (an absolute path on purpose)
        $this->formdir = sys_get_temp_dir() . '/inclform_test_' . uniqid('', true);
        mkdir($this->formdir . '/sub', 0777, true);
        $this->formdir = realpath($this->formdir);
        $conf['plugin']['inclform']['formdir'] = $this->formdir;

        // a "secret" PHP file next to (not inside) the form directory
        $this->outsideFile = dirname($this->formdir) . '/inclform_secret_' . uniqid('', true) . '.php';
        file_put_contents($this->outsideFile, 'SECRET');
    }

    public function tearDown(): void
    {
        $this->removeTree($this->formdir);
        @unlink($this->outsideFile);
        parent::tearDown();
    }

    /**
     * Create a form file inside the form directory
     *
     * @param string $id      wiki style ID of the form
     * @param string $content PHP/HTML source
     */
    protected function createForm($id, $content)
    {
        $file = $this->formdir . '/' . str_replace(':', '/', $id) . '.php';
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    /**
     * Render wiki text like a page view would do
     *
     * @param string $wikitext
     * @param array  $info     receives the renderer info (cache flag etc.)
     * @return string
     */
    protected function render($wikitext, &$info = [])
    {
        return p_render('xhtml', p_get_instructions($wikitext), $info);
    }

    /**
     * Call a protected method of the plugin
     */
    protected function callProtected($method, ...$args)
    {
        $plugin = plugin_load('syntax', 'inclform');
        $m = new \ReflectionMethod($plugin, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($plugin, $args);
    }

    // -- parsing -----------------------------------------------------------

    public function testParsesNameAndParameters()
    {
        $plugin = plugin_load('syntax', 'inclform');
        $handler = $this->createMock(\Doku_Handler::class);

        $data = $plugin->handle('~~INCLFORM=Wiki:My Form;a=1;b=x=y;flag;;=novalue; c = 3 ~~', DOKU_LEXER_SPECIAL, 0, $handler);

        $this->assertSame('wiki:my_form', $data['form'], 'form name is cleaned to a wiki ID');
        $this->assertSame(
            ['a' => '1', 'b' => 'x=y', 'flag' => '', 'c' => ' 3 '],
            $data['params'],
            'only the first = splits, keyless items are dropped, a lone key gets an empty value'
        );
    }

    public function testTagWithoutName()
    {
        $plugin = plugin_load('syntax', 'inclform');
        $data = $plugin->handle('~~INCLFORM~~', DOKU_LEXER_SPECIAL, 0, $this->createMock(\Doku_Handler::class));

        $this->assertSame(['form' => '', 'params' => []], $data);
    }

    // -- rendering ---------------------------------------------------------

    public function testIncludesForm()
    {
        $this->createForm('hello', '<p>Hello <?php echo "World"; ?></p>');

        $this->assertStringContainsString('<p>Hello World</p>', $this->render('~~INCLFORM=hello~~'));
    }

    public function testNamespacedForm()
    {
        $this->createForm('sub:nested', 'nested form');

        $this->assertStringContainsString('nested form', $this->render('~~INCLFORM=sub:nested~~'));
    }

    public function testDefaultsToCurrentPage()
    {
        global $ID;
        $ID = 'sub:mypage';
        $this->createForm('sub:mypage', 'form of the current page');

        $this->assertStringContainsString('form of the current page', $this->render('~~INCLFORM~~'));
    }

    public function testParametersAreEscapedAndRawAvailable()
    {
        $this->createForm(
            'params',
            '<?php echo $FORM_PARAMS["name"], "|", $FORM_PARAMS_RAW["name"];'
        );

        $out = $this->render('~~INCLFORM=params;name=<b>"x"</b>~~');

        $this->assertStringContainsString('&lt;b&gt;&quot;x&quot;&lt;/b&gt;|<b>"x"</b>', $out);
    }

    public function testParametersDoNotLeakBetweenIncludes()
    {
        $this->createForm('first', '<?php echo "first:", json_encode($FORM_PARAMS);');
        $this->createForm('second', '<?php echo "second:", json_encode($FORM_PARAMS);');

        $out = $this->render("~~INCLFORM=first;a=1~~\n\n~~INCLFORM=second;b=2~~");

        // the output of a form is HTML and is inserted as it is
        $this->assertStringContainsString('first:{"a":"1"}', $out);
        $this->assertStringContainsString('second:{"b":"2"}', $out);
    }

    public function testFormCannotSeePluginInternals()
    {
        $this->createForm('scope', '<?php echo isset($this) ? "has-this" : "no-this";');

        $this->assertStringContainsString('no-this', $this->render('~~INCLFORM=scope~~'));
    }

    public function testRenderingDisablesTheCache()
    {
        $this->createForm('dynamic', 'x');

        $this->render('~~INCLFORM=dynamic~~', $info);

        $this->assertFalse($info['cache'], 'pages with a form must never be cached');
    }

    public function testNoOutputForOtherFormats()
    {
        $this->createForm('hello', 'should not appear');

        // call the plugin directly: the metadata renderer would need a real page
        $plugin = plugin_load('syntax', 'inclform');
        $renderer = new \Doku_Renderer_xhtml();

        $handled = $plugin->render('metadata', $renderer, ['form' => 'hello', 'params' => []]);

        $this->assertFalse($handled);
        $this->assertSame('', $renderer->doc);
    }

    // -- error handling ----------------------------------------------------

    public function testMissingFormIsSilentForVisitors()
    {
        global $INFO;
        $INFO['isadmin'] = false;
        $INFO['msg'] = null;

        $out = $this->render('~~INCLFORM=doesnotexist~~');

        $this->assertStringNotContainsString('doesnotexist', $out);
        $this->assertStringNotContainsString('Warning', $out);
    }

    public function testAdminGetsAMessageForAMissingForm()
    {
        global $INFO, $MSG;
        $INFO['isadmin'] = true;
        $MSG = [];

        $this->render('~~INCLFORM=<script>nope~~');

        $this->assertNotEmpty($MSG, 'administrators are told about the problem');
        $this->assertStringNotContainsString('<script>', $MSG[0]['msg'], 'the message is escaped');
    }

    public function testExceptionInFormDoesNotBreakThePage()
    {
        $this->createForm('broken', 'before<?php throw new \RuntimeException("boom");');
        $levelBefore = ob_get_level();

        $out = $this->render("start\n\n~~INCLFORM=broken~~\n\nend");

        $this->assertStringContainsString('start', $out);
        $this->assertStringContainsString('end', $out);
        $this->assertStringNotContainsString('before', $out, 'partial output of a failed form is dropped');
        $this->assertStringNotContainsString('boom', $out, 'error details are not shown to visitors');
        $this->assertSame($levelBefore, ob_get_level(), 'output buffers must stay balanced');
    }

    public function testFormWithParseErrorDoesNotBreakThePage()
    {
        $this->createForm('syntaxerror', '<?php this is not php');

        $out = $this->render("start\n\n~~INCLFORM=syntaxerror~~\n\nend");

        $this->assertStringContainsString('start', $out);
        $this->assertStringContainsString('end', $out);
    }

    public function testNestedOutputBuffersOfAFormAreCleanedUp()
    {
        $this->createForm('leaky', '<?php ob_start(); echo "x"; throw new \Exception("leak");');
        $levelBefore = ob_get_level();

        $this->render('~~INCLFORM=leaky~~');

        $this->assertSame($levelBefore, ob_get_level());
    }

    // -- path handling / security -----------------------------------------

    public function testResolvesInsideFormDirectoryOnly()
    {
        $this->createForm('ok', 'ok');

        $this->assertSame(
            $this->formdir . DIRECTORY_SEPARATOR . 'ok.php',
            $this->callProtected('resolveFormFile', 'ok')
        );
        $this->assertNull($this->callProtected('resolveFormFile', 'missing'));
        $this->assertNull($this->callProtected('resolveFormFile', ''));
    }

    /**
     * Even if a traversal sequence made it past ID cleaning, the resolved
     * path check must refuse it.
     *
     * @dataProvider traversalProvider
     */
    public function testPathTraversalIsRefused($id)
    {
        $secret = basename($this->outsideFile, '.php');
        $id = str_replace('SECRET', $secret, $id);

        $this->assertNull($this->callProtected('resolveFormFile', $id));
    }

    public static function traversalProvider()
    {
        return [
            'parent directory'      => ['../SECRET'],
            'nested parent'         => ['sub/../../SECRET'],
            'wiki style parent'     => ['..:SECRET'],
            'deep parent'           => ['../../../../../../../../SECRET'],
        ];
    }

    public function testTraversalThroughTheWikiSyntaxIncludesNothing()
    {
        $secret = basename($this->outsideFile, '.php');

        $out = $this->render('~~INCLFORM=..:' . $secret . '~~');

        $this->assertStringNotContainsString('SECRET', $out);
    }

    public function testSymlinkLeavingTheFormDirectoryIsRefused()
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Creating symlinks needs special privileges on Windows');
        }
        symlink($this->outsideFile, $this->formdir . '/link.php');

        $this->assertNull($this->callProtected('resolveFormFile', 'link'));
    }

    public function testFormDirectoryRelativeToDokuWikiRoot()
    {
        global $conf;
        // "inc" exists below the DokuWiki root in every installation
        $conf['plugin']['inclform']['formdir'] = 'inc';

        $this->assertSame(realpath(DOKU_INC . 'inc'), $this->callProtected('getFormDirectory'));
    }

    public function testMissingFormDirectory()
    {
        global $conf;
        $conf['plugin']['inclform']['formdir'] = $this->formdir . '/does/not/exist';

        $this->assertNull($this->callProtected('getFormDirectory'));
        $this->assertStringNotContainsString('hello', $this->render('~~INCLFORM=hello~~'));
    }

    // -- helpers -----------------------------------------------------------

    /**
     * Remove a directory tree (the test form directory)
     */
    protected function removeTree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
