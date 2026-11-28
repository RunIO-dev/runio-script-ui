<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;
use Runio\ScriptUi\ToolFinder;

/**
 * Variables for the tools of a folder. A tool is a plain script that the
 * library includes, so it has no variables but its own. An application that
 * keeps its services in objects, and not in globals, hands them over by name.
 */
final class VariablesTest extends ToolTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/script-ui-variables-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        // Says what it was given, and everything it can see.
        file_put_contents($this->dir . '/orders.php', <<<'PHP'
            <?php
            // Orders

            use function Runio\ScriptUi\{title, note, button};

            title('Orders');
            note('Seen: ' . implode(', ', array_keys(get_defined_vars())));
            note('Shop: ' . (isset($shop) ? $shop->name : 'none') . ', limit: ' . ($limit ?? 'none'));

            if (isset($shop)) {
                $shop->visits++;
            }
            // A tool does with its variables what it likes. They are its own.
            $limit = 'changed by the tool';
            $extra = 'made by the tool';

            if (button('Go')) {
                note('Pressed with ' . (isset($shop) ? $shop->name : 'no shop'));
            }
            PHP);
        file_put_contents($this->dir . '/plain.php', <<<'PHP'
            <?php
            // Plain

            use function Runio\ScriptUi\{title, note};

            title('Plain');
            note('Seen: ' . implode(', ', array_keys(get_defined_vars())));
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);

        parent::tearDown();
    }

    /**
     * @param array<array-key,mixed> $variables
     * @param array<string,mixed>    $post
     */
    private function serve(array $variables, string $path = '/orders', array $post = []): Response
    {
        $this->config = new Config(auth: static fn (): string => 'ava', debug: true, variables: $variables);
        if ($post !== []) {
            $post += ['_token' => $this->token()];
        }

        return $this->kernel()->serve(new Request($post === [] ? 'GET' : 'POST', $path, [], $post), new ToolFinder($this->dir), '');
    }

    /** @return list<string> */
    private function notes(Response $response): array
    {
        return $this->texts($response, '//p[contains(@class,"sui-note")]');
    }

    private static function shop(): object
    {
        $shop = new \stdClass();
        $shop->name = 'Books';
        $shop->visits = 0;

        return $shop;
    }

    public function testAToolGetsTheVariablesByTheirNames(): void
    {
        $shop = self::shop();

        $response = $this->serve(['shop' => $shop, 'limit' => 20]);

        self::assertSame(200, $response->status);
        self::assertSame(['Seen: shop, limit', 'Shop: Books, limit: 20'], $this->notes($response));
        // An object is the object of the application itself, not a copy.
        self::assertSame(1, $shop->visits);
    }

    public function testEveryRunAndEveryToolStartsWithTheVariablesAsTheyWereGiven(): void
    {
        $shop = self::shop();
        $variables = ['shop' => $shop, 'limit' => 20];

        // The tool overwrote $limit and made $extra. Neither is there the next time.
        $this->serve($variables);
        $again = $this->serve($variables);
        self::assertSame(['Seen: shop, limit', 'Shop: Books, limit: 20'], $this->notes($again));

        $pressed = $this->serve($variables, post: ['_click' => 'go']);
        self::assertSame(['Seen: shop, limit', 'Shop: Books, limit: 20', 'Pressed with Books'], $this->notes($pressed));

        self::assertSame(['Seen: shop, limit'], $this->notes($this->serve($variables, '/plain')));
        self::assertSame(20, $variables['limit']);
    }

    public function testWithoutVariablesAToolSeesNothingAtAll(): void
    {
        // Not even where its own file lies: nothing of the library is in its way.
        self::assertSame(['Seen:'], $this->notes($this->serve([], '/plain')));
        self::assertSame(['Seen:', 'Shop: none, limit: none'], $this->notes($this->serve([])));
    }

    public function testAVariableMayBeNothing(): void
    {
        // null is a value like any other: the variable is there.
        self::assertSame(['Seen: shop, limit', 'Shop: none, limit: none'], $this->notes($this->serve(['shop' => null, 'limit' => null])));
    }

    public function testNamesInOtherLettersAreNames(): void
    {
        self::assertSame(['Seen: طلبات, größe, _x, a1'], $this->notes($this->serve(['طلبات' => 1, 'größe' => 2, '_x' => 3, 'a1' => 4], '/plain')));
    }

    /** @return iterable<string, array{array<array-key,mixed>, string}> */
    public static function badNames(): iterable
    {
        yield 'a list without names' => [['shop'], 'needs a name for every value'];
        yield 'a number as a name' => [[5 => 'x'], 'needs a name for every value'];
        yield 'a name that starts with a digit' => [['1st' => 1], '"1st" cannot be the name of a variable'];
        yield 'a name with a space' => [['my shop' => 1], '"my shop" cannot be the name of a variable'];
        yield 'a name with a dollar sign' => [['$shop' => 1], '"$shop" cannot be the name of a variable'];
        yield 'a name with a dash' => [['my-shop' => 1], '"my-shop" cannot be the name of a variable'];
        yield 'an empty name' => [['' => 1], '"" cannot be the name of a variable'];
        yield 'this' => [['this' => 1], '"this" belongs to PHP'];
        yield 'the globals' => [['GLOBALS' => 1], '"GLOBALS" belongs to PHP'];
        yield 'the session' => [['_SESSION' => []], '"_SESSION" belongs to PHP'];
        yield 'the posted fields' => [['_POST' => []], '"_POST" belongs to PHP'];
        yield 'the server' => [['_SERVER' => []], '"_SERVER" belongs to PHP'];
        yield 'a name with a line break at its end' => [["shop\n" => 1], 'cannot be the name of a variable'];
    }

    /**
     * @param array<array-key,mixed> $variables
     */
    #[DataProvider('badNames')]
    public function testANameThatIsNoneIsRefusedWhenTheToolsAreSetUp(array $variables, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($why);

        new Config(variables: $variables);
    }

    public function testTheSettingIsKeptAndReplacedLikeTheOthers(): void
    {
        $config = (new Config(name: 'Back office'))->with(variables: ['limit' => 20]);
        self::assertSame(['limit' => 20], $config->variables);
        self::assertSame('Back office', $config->name);

        // Another setting leaves them alone. New ones take the place of the old.
        self::assertSame(['limit' => 20], $config->with(name: 'Other')->variables);
        self::assertSame(['shop' => 'x'], $config->with(variables: ['shop' => 'x'])->variables);
        self::assertSame([], $config->with(variables: [])->variables);
        self::assertSame([], (new Config())->variables);

        // Names are checked there too.
        $this->expectException(\InvalidArgumentException::class);
        $config->with(variables: ['this' => 1]);
    }
}
