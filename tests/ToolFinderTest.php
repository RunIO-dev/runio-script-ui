<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\ToolFinder;

final class ToolFinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/script-ui-finder-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/sub', 0777, true);
    }

    protected function tearDown(): void
    {
        // scandir() and not glob() with GLOB_BRACE: that flag does not exist on every system (Alpine Linux).
        foreach ([$this->dir . '/sub', $this->dir] as $folder) {
            foreach (scandir($folder) ?: [] as $name) {
                if (is_file($folder . '/' . $name)) {
                    unlink($folder . '/' . $name);
                }
            }
        }
        rmdir($this->dir . '/sub');
        rmdir($this->dir);
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->dir . '/' . $name, $php);
    }

    public function testItListsThePhpFilesInTheFolder(): void
    {
        $this->write('refund.php', "<?php\n// Refund an order\n// Look up a customer and refund\n// what they bought.\n\ntitle('x');\n");
        $this->write('dashboard.php', "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Sales dashboard\n *\n * Revenue and orders for the last 30 days.\n */\n\nrun();\n");
        $this->write('bulk-import_v2.php', "<?php\nrun();\n");
        $this->write('_bootstrap.php', "<?php // shared helpers\n");
        $this->write('notes.txt', 'not php');
        $this->write('Bad Name.php', "<?php // has a space\n");
        $this->write('.hidden.php', "<?php // hidden\n");
        $this->write('sub/inner.php', "<?php // in a sub folder\n");

        $tools = (new ToolFinder($this->dir))->all();

        self::assertSame(['bulk-import_v2', 'refund', 'dashboard'], array_keys($tools), 'sorted by title');

        self::assertSame('Refund an order', $tools['refund']->title);
        self::assertSame('Look up a customer and refund what they bought.', $tools['refund']->description);
        self::assertSame('Sales dashboard', $tools['dashboard']->title);
        self::assertSame('Revenue and orders for the last 30 days.', $tools['dashboard']->description);
        self::assertSame('Bulk import v2', $tools['bulk-import_v2']->title);
        self::assertSame('', $tools['bulk-import_v2']->description);
        self::assertSame('refund', $tools['refund']->id);
        self::assertSame(realpath($this->dir . '/refund.php'), realpath($tools['refund']->file));
    }

    public function testItFindsOneToolByItsId(): void
    {
        $this->write('refund.php', "<?php // Refund\n");
        $this->write('_bootstrap.php', "<?php // helper\n");
        $this->write('sub/inner.php', "<?php // inner\n");
        $finder = new ToolFinder($this->dir);

        $tool = $finder->find('refund');
        self::assertNotNull($tool);
        self::assertSame('Refund', $tool->title);

        $refused = ['', '_bootstrap', '../refund', 'refund.php', 'sub/inner', 'sub', 'missing', 'refund/', "refund\0", 'REFUND', '.', '..'];
        foreach ($refused as $id) {
            self::assertNull($finder->find($id), var_export($id, true));
        }
    }

    public function testAFolderWithTheNameOfAToolIsNotATool(): void
    {
        $this->write('refund.php', "<?php\n// Refund\n");
        mkdir($this->dir . '/archive.php');

        try {
            $finder = new ToolFinder($this->dir);

            self::assertSame(['refund'], array_keys($finder->all()));
            self::assertNull($finder->find('archive'));
        } finally {
            rmdir($this->dir . '/archive.php');
        }
    }

    public function testTheNameOfAFileSaysWhichToolItIs(): void
    {
        self::assertSame('refund', ToolFinder::idOf('refund.php'));
        self::assertSame('bulk-import_v2', ToolFinder::idOf('bulk-import_v2.php'));
        self::assertSame('2024', ToolFinder::idOf('2024.php'));

        // Not the name of a tool: a helper, another ending, another case, a
        // name with something in it that an address should not carry.
        foreach (['_bootstrap.php', 'refund.PHP', 'refund.phtml', 'refund', 'Refund.php', 'my report.php', 'report.v2.php', '.php', '../refund.php', "refund.php\n"] as $name) {
            self::assertNull(ToolFinder::idOf($name), $name);
        }
        self::assertNull(ToolFinder::idOf(str_repeat('a', 65) . '.php'));
        self::assertSame(str_repeat('a', 64), ToolFinder::idOf(str_repeat('a', 64) . '.php'));
    }

    public function testAFolderThatDoesNotExistHasNoTools(): void
    {
        $finder = new ToolFinder($this->dir . '/nowhere');

        self::assertSame([], $finder->all());
        self::assertNull($finder->find('refund'));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function headers(): iterable
    {
        yield 'comment on the first line' => ["<?php // Quick title\nrun();", 'Quick title', ''];
        yield 'hash comment' => ["<?php\n# Hash title\n# With more.\nrun();", 'Hash title', 'With more.'];
        yield 'block comment' => ["<?php\n/* Block title */\nrun();", 'Block title', ''];
        yield 'after declare' => ["<?php\ndeclare(strict_types=1);\n\n// After declare\nrun();", 'After declare', ''];
        yield 'after a shebang' => ["#!/usr/bin/env php\n<?php\n// With shebang\n", 'With shebang', ''];
        yield 'comment after code does not count' => ["<?php\nrun();\n// Too late\n", 'My tool', ''];
        yield 'no comment at all' => ["<?php\nrun();\n", 'My tool', ''];
        yield 'not php at all' => ['just text', 'My tool', ''];
    }

    #[DataProvider('headers')]
    public function testTheFirstCommentNamesTheTool(string $php, string $title, string $description): void
    {
        $this->write('my_tool.php', $php);

        $tool = (new ToolFinder($this->dir))->find('my_tool');

        self::assertNotNull($tool);
        self::assertSame($title, $tool->title);
        self::assertSame($description, $tool->description);
    }
}
