<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Keys;

final class KeysTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function labels(): iterable
    {
        yield 'words' => ['Customer email', 'customer-email'];
        yield 'punctuation and spaces' => ['  Hello,  World! ', 'hello-world'];
        yield 'digits' => ['Last 30 days', 'last-30-days'];
        yield 'nothing usable' => ['?!', 'field'];
        yield 'empty' => ['', 'field'];
    }

    #[DataProvider('labels')]
    public function testSlugTurnsALabelIntoAFieldName(string $label, string $expected): void
    {
        self::assertSame($expected, Keys::slug($label));
    }

    public function testSlugKeepsLettersFromOtherScripts(): void
    {
        self::assertSame('البريد-الإلكتروني', Keys::slug('البريد الإلكتروني'));
    }

    public function testSlugIsNeverLongerThan64Characters(): void
    {
        self::assertLessThanOrEqual(64, mb_strlen(Keys::slug(str_repeat('very long label ', 20))));
    }

    public function testAnExplicitKeyMustBeSafeToUseAsAFieldName(): void
    {
        self::assertSame('order_id-2', Keys::assertValid('order_id-2'));

        $this->expectException(\InvalidArgumentException::class);
        Keys::assertValid('_token');
    }

    public function testAnExplicitKeyCannotContainMarkup(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Keys::assertValid('a"><script>');
    }
}
