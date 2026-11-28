<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use PHPUnit\Framework\TestCase;
use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Kernel;
use Runio\ScriptUi\Session\FrameworkSession;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\multiselect;
use function Runio\ScriptUi\number;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\title;

/**
 * The session of a framework as the place where the library keeps its state.
 * Neither framework is installed here. The two classes at the end of this file
 * have the methods of their sessions, and the last one keeps everything as
 * JSON, as Laravel does unless it is told otherwise.
 */
final class FrameworkSessionTest extends TestCase
{
    public function testEverythingSitsUnderOneKeyOfASymfonySession(): void
    {
        $symfony = new SymfonyLikeSession(['_sf2_attributes' => 'theirs', 'cart' => [1, 2]]);
        $session = new FrameworkSession($symfony);

        self::assertNull($session->get('csrf'));
        self::assertSame('none', $session->get('csrf', 'none'));

        $session->set('csrf', 'abc');
        $session->set('state', ['refund' => ['note' => 'late']]);

        self::assertSame('abc', $session->get('csrf'));
        self::assertSame(['refund' => ['note' => 'late']], $session->get('state'));
        self::assertSame(
            ['_sf2_attributes' => 'theirs', 'cart' => [1, 2], '_script_ui' => ['csrf' => 'abc', 'state' => ['refund' => ['note' => 'late']]]],
            $symfony->data,
        );

        $session->remove('csrf');
        self::assertNull($session->get('csrf'));
        self::assertSame(['state' => ['refund' => ['note' => 'late']]], $symfony->data['_script_ui']);
        // What belongs to the application is never touched.
        self::assertSame([1, 2], $symfony->data['cart']);
    }

    public function testALaravelSessionIsWrittenWithPut(): void
    {
        $laravel = new LaravelLikeSession();
        $session = new FrameworkSession($laravel);

        $session->set('csrf', 'abc');
        $session->remove('gone');

        self::assertSame('abc', $session->get('csrf'));
        self::assertSame(['_script_ui' => ['csrf' => 'abc']], $laravel->all());
    }

    public function testTheSessionHelperOfLaravelIsTakenToo(): void
    {
        // session() in Laravel hands out a manager. It has none of the methods
        // itself and passes every call on to the session, which driver() returns.
        $laravel = new LaravelLikeSession();
        $manager = new class ($laravel) {
            public function __construct(private readonly LaravelLikeSession $store)
            {
            }

            public function driver(): LaravelLikeSession
            {
                return $this->store;
            }

            /** @param array<array-key,mixed> $arguments */
            public function __call(string $method, array $arguments): mixed
            {
                return $this->store->$method(...$arguments);
            }
        };

        $session = new FrameworkSession($manager);
        $session->set('csrf', 'abc');

        self::assertSame(['_script_ui' => ['csrf' => 'abc']], $laravel->all());
    }

    public function testASessionIsNotLookedBehindWhenItIsOne(): void
    {
        // Only an object that cannot be read itself is asked for what is behind
        // it. A session that happens to have a method of that name is the session.
        $session = new class () {
            /** @var array<string,mixed> */
            public array $data = [];

            public function get(string $name, mixed $default = null): mixed
            {
                return $this->data[$name] ?? $default;
            }

            public function set(string $name, mixed $value): void
            {
                $this->data[$name] = $value;
            }

            public function driver(): object
            {
                return new \stdClass();
            }
        };

        (new FrameworkSession($session))->set('csrf', 'abc');

        self::assertSame(['_script_ui' => ['csrf' => 'abc']], $session->data);
    }

    public function testAnObjectThatIsNoSessionIsRefusedWithAHint(): void
    {
        $onlyReads = new class () {
            public function get(string $key): mixed
            {
                return null;
            }
        };
        $onlyWrites = new class () {
            public function set(string $key, mixed $value): void
            {
            }

            public function put(string $key, mixed $value): void
            {
            }
        };

        foreach ([new \stdClass(), new \ArrayObject(), $onlyReads, $onlyWrites] as $object) {
            try {
                new FrameworkSession($object);
                self::fail('Taken as a session: ' . get_debug_type($object));
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('session: has to be the session of your application', $error->getMessage());
                self::assertStringContainsString('get() and set(), as in Symfony', $error->getMessage());
                self::assertStringContainsString('get() and put(), as in Laravel', $error->getMessage());
            }
        }

        // The class that was passed is named, so the mistake is easy to find.
        $this->expectExceptionMessage('ArrayObject is neither.');
        new FrameworkSession(new \ArrayObject());
    }

    public function testSomethingElseUnderTheKeyIsTakenForNothing(): void
    {
        $symfony = new SymfonyLikeSession(['_script_ui' => 'not ours']);
        $session = new FrameworkSession($symfony);

        self::assertNull($session->get('csrf'));

        $session->set('csrf', 'abc');
        self::assertSame(['csrf' => 'abc'], $symfony->data['_script_ui']);
    }

    public function testWhatIsKeptFitsIntoJson(): void
    {
        // Laravel keeps a session as JSON by default, and JSON takes no text that
        // is not UTF-8. A single such byte in a field somebody posted, and the
        // whole session of that user could not be saved any more.
        $laravel = new JsonSession();
        $session = new FrameworkSession($laravel);

        $session->set('state', [
            'refund' => ['note' => "caf\xE9", 'tags' => ["a\xFFb", 'fine'], "k\xFEy" => 'x', 'count' => 3, 'on' => true, 'none' => null, 'rate' => 1.5],
        ]);
        $session->set('flash', "broken \xC3 text");

        self::assertSame(
            ['refund' => ['note' => 'caf?', 'tags' => ['a?b', 'fine'], 'k?y' => 'x', 'count' => 3, 'on' => true, 'none' => null, 'rate' => 1.5]],
            $session->get('state'),
        );
        self::assertSame('broken ? text', $session->get('flash'));

        // A number that JSON has no word for.
        $session->set('odd', ['rate' => INF, 'other' => NAN]);
        self::assertSame(['rate' => null, 'other' => null], $session->get('odd'));

        // Text in any script is left as it is.
        $session->set('name', 'مرحبا, Grüße');
        self::assertSame('مرحبا, Grüße', $session->get('name'));
    }

    public function testSavingIsPassedOnToASessionThatCanBeSaved(): void
    {
        $laravel = new LaravelLikeSession();
        (new FrameworkSession($laravel))->save();
        self::assertSame(1, $laravel->saved);

        // A session without save() saves itself. Nothing to do, and no error.
        (new FrameworkSession(new class () {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function set(string $key, mixed $value): void
            {
            }
        }))->save();
    }

    public function testAToolKeepsItsStateInASessionThatIsKeptAsJson(): void
    {
        $laravel = new JsonSession();
        $config = new Config(auth: static fn (): string => 'ava', debug: true);
        $tool = static function (): void {
            title('Refund');
            $note = text('Note');
            $amount = number('Amount', default: 10);
            $why = multiselect('Why', ['late' => 'Late', 'broken' => 'Broken', 'wrong' => 'Wrong item']);
            if (button('Go')) {
                success('Done: ' . $note . ' / ' . json_encode($amount) . ' / ' . implode('+', $why));
            }
        };
        // Every request gets a kernel and an adapter of its own, as in a real application.
        $run = static fn (Request $request): Response => (new Kernel($config, new FrameworkSession($laravel)))->handle($request, 'refund', $tool);

        $page = $run(new Request('GET', '/refund'));
        self::assertSame(200, $page->status);
        preg_match('/name="_token" value="([0-9a-f]+)"/', $page->body, $token);

        // A pressed button, with a byte in the note that is not UTF-8.
        $pressed = $run(new Request('POST', '/refund', [], [
            '_token' => $token[1], '_click' => 'go', 'note' => "caf\xE9", 'amount' => '12.5', 'why' => ['late', 'wrong'],
        ]));
        self::assertSame(200, $pressed->status);
        self::assertStringContainsString('12.5 / late+wrong', $pressed->body);

        // The session was written, and it is JSON: read back, the page has what was typed.
        self::assertGreaterThan(0, $laravel->writes);
        $again = $run(new Request('GET', '/refund'));
        self::assertSame(200, $again->status);
        self::assertStringContainsString('value="12.5"', $again->body);
        self::assertSame(2, preg_match_all('/<input[^>]*type="checkbox"[^>]*checked/', $again->body));
        self::assertStringContainsString('value="caf', $again->body);

        // The same token still opens the form: it lives in that session too.
        $second = $run(new Request('POST', '/refund', [], ['_token' => $token[1], '_click' => 'go', 'note' => 'x', 'amount' => '1', 'why' => []]));
        self::assertSame(200, $second->status);
        self::assertStringContainsString('Done: x / 1 /', $second->body);
    }
}

/** The methods of Symfony's session that matter here. */
final class SymfonyLikeSession
{
    /** @param array<string,mixed> $data */
    public function __construct(public array $data = [])
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->data) ? $this->data[$name] : $default;
    }

    public function set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }
}

/** The methods of Laravel's session that matter here. It has no set(). */
class LaravelLikeSession
{
    /** @var array<string,mixed> */
    protected array $attributes = [];

    public int $saved = 0;

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function put(string $key, mixed $value = null): void
    {
        $this->attributes[$key] = $value;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->attributes;
    }

    public function save(): void
    {
        $this->saved++;
    }
}

/** A session that is kept as JSON: what cannot be written as JSON cannot be kept. */
final class JsonSession extends LaravelLikeSession
{
    public int $writes = 0;

    private string $json = '{}';

    public function get(string $key, mixed $default = null): mixed
    {
        /** @var array<string,mixed> $all */
        $all = json_decode($this->json, true, 512, JSON_THROW_ON_ERROR);

        return $all[$key] ?? $default;
    }

    public function put(string $key, mixed $value = null): void
    {
        /** @var array<string,mixed> $all */
        $all = json_decode($this->json, true, 512, JSON_THROW_ON_ERROR);
        $all[$key] = $value;
        $this->json = json_encode($all, JSON_THROW_ON_ERROR);
        $this->writes++;
    }
}
