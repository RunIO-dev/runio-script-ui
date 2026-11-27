<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Upload;
use Runio\ScriptUi\UploadFolder;

/**
 * The folder that keeps uploaded files between the runs of a tool.
 */
final class UploadFolderTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/script-ui-test-' . bin2hex(random_bytes(6));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        self::remove($this->base);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @chmod($path, 0700);
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** A file as PHP hands it over: it lies somewhere under a name of PHP's choosing. */
    private function arrived(string $content): string
    {
        $file = $this->base . '/arrived-' . bin2hex(random_bytes(4));
        file_put_contents($file, $content);

        return $file;
    }

    private function folder(?string $path = null, ?int $now = null): UploadFolder
    {
        return new UploadFolder(
            $path ?? $this->base . '/uploads',
            rename(...),
            clock: $now === null ? null : static fn (): int => $now,
        );
    }

    private static function hasPermissions(): bool
    {
        return DIRECTORY_SEPARATOR === '/';
    }

    public function testAFileIsKeptUnderARandomName(): void
    {
        $folder = $this->folder();

        $first = $folder->keep($this->arrived('one'));
        $second = $folder->keep($this->arrived('two'));

        // The ending says whose file it is. Nothing runs or opens a ".upload".
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.upload$/', $first);
        self::assertNotSame($first, $second);
        self::assertSame('one', file_get_contents($folder->path($first)));
        self::assertSame($this->base . '/uploads/' . $second, $folder->path($second));
    }

    public function testTheFolderAndItsFilesAreForThisUserOnly(): void
    {
        if (!self::hasPermissions()) {
            self::markTestSkipped('This system has no file permissions of this kind.');
        }

        $folder = $this->folder();
        $file = $folder->keep($this->arrived('private'));

        self::assertSame(0700, fileperms($this->base . '/uploads') & 0777);
        self::assertSame(0600, fileperms($folder->path($file)) & 0777);
    }

    public function testAFolderOfItsOwnIsMadeInsideTheTempFolder(): void
    {
        $folder = new UploadFolder(null, rename(...));
        $file = $folder->keep($this->arrived('x'));
        $path = $folder->path($file);

        try {
            self::assertStringStartsWith(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'script-ui-', $path);
            if (self::hasPermissions()) {
                self::assertSame(0700, fileperms(dirname($path)) & 0777);
            }
        } finally {
            $folder->delete($file);
        }
    }

    public function testATempFolderThatOthersCanGetIntoIsNotUsed(): void
    {
        if (!self::hasPermissions()) {
            self::markTestSkipped('This system has no file permissions of this kind.');
        }

        // Somebody else on the machine made the folder first and left it open.
        $open = $this->base . '/open';
        mkdir($open, 0777);
        chmod($open, 0777);
        $folder = new UploadFolder($open, rename(...), strict: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is open to other users');
        $folder->keep($this->arrived('x'));
    }

    public function testALinkIsNotAFolder(): void
    {
        if (!self::hasPermissions()) {
            self::markTestSkipped('This system has no links of this kind.');
        }

        mkdir($this->base . '/elsewhere', 0700);
        symlink($this->base . '/elsewhere', $this->base . '/link');
        $folder = new UploadFolder($this->base . '/link', rename(...), strict: true);

        $this->expectException(\RuntimeException::class);
        $folder->keep($this->arrived('x'));
    }

    public function testAFolderThatCannotBeMadeIsAnErrorThatSaysWhatToDo(): void
    {
        file_put_contents($this->base . '/a-file', '');
        $folder = $this->folder($this->base . '/a-file/uploads');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('uploads:');
        $folder->keep($this->arrived('x'));
    }

    public function testAFileThatCannotBeMovedIsAnError(): void
    {
        $folder = new UploadFolder($this->base . '/uploads', static fn (string $from, string $to): bool => false);

        $this->expectException(\RuntimeException::class);
        $folder->keep($this->arrived('x'));
    }

    public function testOnlyAStoredNameNamesAFile(): void
    {
        $folder = $this->folder();
        $folder->keep($this->arrived('x'));

        $hex = str_repeat('a', 32);
        foreach (['../secret', 'a/b', '', '.', '..', str_repeat('g', 32) . '.upload', str_repeat('a', 31) . '.upload', $hex, $hex . ".upload\n", $hex . '.upload.php', '../' . $hex . '.upload'] as $name) {
            try {
                $folder->path($name);
                self::fail('"' . $name . '" was taken for the name of a stored file');
            } catch (\LogicException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDeletingAFileTwiceIsFine(): void
    {
        $folder = $this->folder();
        $file = $folder->keep($this->arrived('x'));

        $folder->delete($file);
        $folder->delete($file);
        $folder->delete('../not-a-stored-name');

        self::assertFileDoesNotExist($this->base . '/uploads/' . $file);
    }

    public function testFilesOlderThanADayAreCleanedAway(): void
    {
        $folder = $this->folder();
        $old = $folder->keep($this->arrived('old'));
        $new = $folder->keep($this->arrived('new'));
        touch($folder->path($old), time() - UploadFolder::LIFETIME - 60);
        // Something that is not ours stays, however old it is. That goes for a file with
        // a name made of a hash too: caches write such names, and a folder may be shared.
        $others = ['notes.txt', md5('somebody else'), md5('somebody else') . '.cache'];
        foreach ($others as $other) {
            file_put_contents($this->base . '/uploads/' . $other, 'keep me');
            touch($this->base . '/uploads/' . $other, time() - 10 * UploadFolder::LIFETIME);
        }

        self::assertSame(1, $folder->clean());

        self::assertFileDoesNotExist($this->base . '/uploads/' . $old);
        self::assertFileExists($folder->path($new));
        foreach ($others as $other) {
            self::assertFileExists($this->base . '/uploads/' . $other);
        }
    }

    public function testTheFolderIsCleanedNowAndThenWhileFilesArrive(): void
    {
        $start = time();
        $old = $this->folder(now: $start)->keep($this->arrived('old'));
        touch($this->base . '/uploads/' . $old, $start - UploadFolder::LIFETIME - 60);

        // The folder was looked through a moment ago: not again yet.
        $this->folder(now: $start + 60)->keep($this->arrived('a'));
        self::assertFileExists($this->base . '/uploads/' . $old);

        // More than an hour later it is.
        $this->folder(now: $start + 3700)->keep($this->arrived('b'));
        self::assertFileDoesNotExist($this->base . '/uploads/' . $old);
    }

    public function testCleaningAFolderThatIsNotThereFindsNothing(): void
    {
        self::assertSame(0, $this->folder($this->base . '/never-made')->clean());
        self::assertDirectoryDoesNotExist($this->base . '/never-made');
    }

    public function testTheLimitsOfPhpDecideHowLargeAFileCanBe(): void
    {
        $limits = static fn (int $file, int $body): UploadFolder => new UploadFolder(null, null, $file, $body);

        self::assertSame(2000, $limits(2000, 8000)->fileLimit());
        self::assertSame(8000, $limits(2000, 8000)->bodyLimit());
        // The whole request has to fit too.
        self::assertSame(500, $limits(2000, 500)->fileLimit());
        // In php.ini a 0 means "no limit".
        self::assertSame(2000, $limits(2000, 0)->fileLimit());
        self::assertNull($limits(2000, 0)->bodyLimit());
        self::assertSame(700, $limits(0, 700)->fileLimit());
        self::assertNull($limits(0, 0)->fileLimit());
    }

    public function testWithoutGivenLimitsThePhpSettingsAreRead(): void
    {
        $folder = new UploadFolder();
        $file = ini_parse_quantity((string) ini_get('upload_max_filesize'));
        $body = ini_parse_quantity((string) ini_get('post_max_size'));
        $expected = array_filter([$file, $body], static fn (int $limit): bool => $limit > 0);

        self::assertSame($expected === [] ? null : min($expected), $folder->fileLimit());
        self::assertSame((bool) ini_get('file_uploads'), $folder->switchedOn());
    }

    public function testPhpAlsoLimitsHowManyFilesOneRequestBrings(): void
    {
        self::assertSame(5, (new UploadFolder(countLimit: 5))->countLimit());
        self::assertSame(max(0, (int) ini_get('max_file_uploads')), (new UploadFolder())->countLimit());

        // No file at all is the same as uploads switched off.
        self::assertFalse((new UploadFolder(switchedOn: true, countLimit: 0))->switchedOn());
        self::assertTrue((new UploadFolder(switchedOn: true, countLimit: 1))->switchedOn());
        self::assertFalse((new UploadFolder(switchedOn: false, countLimit: 20))->switchedOn());
    }

    // ------------------------------------------------------------- the upload

    private function upload(string $content, string $name = 'report.csv', ?\Closure $forget = null): Upload
    {
        $folder = $this->folder();
        $file = $folder->keep($this->arrived($content));

        return new Upload($name, strlen($content), $folder->path($file), $forget ?? static function (): void {
        });
    }

    public function testAnUploadCanBeReadAndCopied(): void
    {
        $upload = $this->upload("id,total\n1,40\n");

        self::assertSame('report.csv', $upload->name);
        self::assertSame(14, $upload->size);
        self::assertSame("id,total\n1,40\n", $upload->contents());

        $upload->saveTo($this->base . '/kept.csv');
        self::assertSame("id,total\n1,40\n", file_get_contents($this->base . '/kept.csv'));
        self::assertFileExists($upload->path, 'saveTo() copies, the upload stays');
    }

    public function testSavingNeverOverwritesUnlessAsked(): void
    {
        $upload = $this->upload('new');
        file_put_contents($this->base . '/there.csv', 'old');

        try {
            $upload->saveTo($this->base . '/there.csv');
            self::fail('an existing file was overwritten');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('already', $error->getMessage());
        }
        self::assertSame('old', file_get_contents($this->base . '/there.csv'));

        $upload->saveTo($this->base . '/there.csv', overwrite: true);
        self::assertSame('new', file_get_contents($this->base . '/there.csv'));
    }

    public function testASavedCopyIsForThisUserOnly(): void
    {
        if (!self::hasPermissions()) {
            self::markTestSkipped('This system has no file permissions of this kind.');
        }

        // What a user uploads is often not meant for everybody on the machine.
        $upload = $this->upload('private');

        $upload->saveTo($this->base . '/new.csv');
        self::assertSame(0600, fileperms($this->base . '/new.csv') & 0777);

        file_put_contents($this->base . '/old.csv', 'old');
        chmod($this->base . '/old.csv', 0644);
        $upload->saveTo($this->base . '/old.csv', overwrite: true);
        self::assertSame(0600, fileperms($this->base . '/old.csv') & 0777);
    }

    public function testOverwritingReplacesALinkAndDoesNotWriteThroughIt(): void
    {
        if (!self::hasPermissions()) {
            self::markTestSkipped('This system has no links of this kind.');
        }

        // Somebody put a link where the copy goes. It points at a file that must stay as it is.
        file_put_contents($this->base . '/victim.txt', 'do not touch');
        symlink($this->base . '/victim.txt', $this->base . '/kept.csv');
        $upload = $this->upload('new');

        try {
            $upload->saveTo($this->base . '/kept.csv');
            self::fail('a link was taken for a free place');
        } catch (\RuntimeException) {
            self::assertSame('do not touch', file_get_contents($this->base . '/victim.txt'));
        }

        $upload->saveTo($this->base . '/kept.csv', overwrite: true);

        self::assertSame('do not touch', file_get_contents($this->base . '/victim.txt'));
        self::assertFalse(is_link($this->base . '/kept.csv'));
        self::assertSame('new', file_get_contents($this->base . '/kept.csv'));
    }

    public function testSavingAFileOverItselfLeavesItWhole(): void
    {
        $upload = $this->upload('still here');

        $upload->saveTo($upload->path, overwrite: true);

        self::assertSame('still here', $upload->contents());
    }

    public function testAnOverwriteThatFailsLeavesTheOldFileAndNothingElse(): void
    {
        $upload = $this->upload('new');
        mkdir($this->base . '/taken', 0700);
        file_put_contents($this->base . '/there.csv', 'old');

        // A folder cannot be replaced by a file.
        try {
            $upload->saveTo($this->base . '/taken', overwrite: true);
            self::fail('a folder was replaced by a file');
        } catch (\RuntimeException) {
            self::assertDirectoryExists($this->base . '/taken');
        }

        // No half-written file is left next to the target.
        self::assertSame(['taken', 'there.csv', 'uploads'], array_values(array_diff(scandir($this->base) ?: [], ['.', '..'])));
        self::assertSame('old', file_get_contents($this->base . '/there.csv'));
    }

    public function testSavingIntoAFolderThatIsNotThereIsAnError(): void
    {
        $upload = $this->upload('x');

        $this->expectException(\RuntimeException::class);
        $upload->saveTo($this->base . '/no/such/folder/x.csv');
    }

    public function testAnUploadThatIsGoneSaysSo(): void
    {
        $upload = $this->upload('x');
        unlink($upload->path);

        try {
            $upload->contents();
            self::fail('a missing file was read');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('report.csv', $error->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $upload->saveTo($this->base . '/kept.csv');
    }

    public function testForgetIsHandedOn(): void
    {
        $forgotten = 0;
        $upload = $this->upload('x', forget: static function () use (&$forgotten): void {
            $forgotten++;
        });

        $upload->forget();

        self::assertSame(1, $forgotten);
    }
}
