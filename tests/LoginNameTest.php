<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\user;

/**
 * The name of whoever is logged in. A login check may return a name. In an
 * application built on Symfony or Laravel it returns the user object of that
 * framework, and the page and the log still need a name for it.
 */
final class LoginNameTest extends ToolTestCase
{
    /** What user() returned inside the tool, after a page view as this login. */
    private function nameOf(mixed $login): ?string
    {
        return $this->view($login)[1];
    }

    /** @return array{Response, ?string} */
    private function view(mixed $login): array
    {
        $this->config = new Config(auth: static fn (): mixed => $login, debug: true);

        $name = 'the tool did not run';
        $response = $this->get(function () use (&$name): void {
            $name = user();
        });

        return [$response, $name];
    }

    public function testAUserOfSymfonyGoesByItsIdentifier(): void
    {
        // The shape of Symfony's UserInterface.
        $user = new class () {
            public function getUserIdentifier(): string
            {
                return 'ava@example.com';
            }

            /** @return list<string> */
            public function getRoles(): array
            {
                return ['ROLE_ADMIN'];
            }
        };

        [$response, $name] = $this->view($user);

        self::assertSame(200, $response->status);
        self::assertSame('ava@example.com', $name);
        self::assertSame(['ava@example.com'], $this->texts($response, '//*[contains(@class,"sui-user")]'));
    }

    public function testAUserOfLaravelGoesByItsIdentifierAndNotByAllOfItsFields(): void
    {
        // The shape of a model of Laravel: it says who it is with
        // getAuthIdentifier(), and as a text it is all of its fields, as JSON.
        $user = new class () implements \Stringable {
            public function getAuthIdentifier(): int
            {
                return 7;
            }

            public function __toString(): string
            {
                return '{"id":7,"name":"Ava","email":"ava@example.com"}';
            }
        };

        [$response, $name] = $this->view($user);

        self::assertSame('7', $name);
        self::assertStringNotContainsString('ava@example.com', $response->body);
    }

    public function testTheNameGoesIntoTheLog(): void
    {
        $user = new class () {
            public function getUserIdentifier(): string
            {
                return 'ava@example.com';
            }
        };
        $this->config = new Config(
            auth: static fn (): object => $user,
            log: function (array $entry): void {
                $this->logged[] = $entry;
            },
            debug: true,
        );

        $this->post(function (): void {
            button('Go');
        }, ['_click' => 'go']);

        self::assertSame('ava@example.com', $this->logged[0]['user']);
    }

    public function testAnObjectThatIsATextGoesByThatText(): void
    {
        $user = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'Ava';
            }
        };

        self::assertSame('Ava', $this->nameOf($user));
    }

    public function testAnObjectWithoutANameIsLoggedInAndNameless(): void
    {
        [$response, $name] = $this->view(new \stdClass());

        self::assertSame(200, $response->status);
        self::assertNull($name);

        // An identifier that is no name: nothing, or something that is not a text.
        $identified = static fn (mixed $id): object => new class ($id) {
            public function __construct(private readonly mixed $id)
            {
            }

            public function getUserIdentifier(): mixed
            {
                return $this->id;
            }
        };
        foreach (['an empty text' => '', 'nothing' => null, 'a list' => ['ava'], 'an object' => new \stdClass()] as $case => $id) {
            [$response, $name] = $this->view($identified($id));

            self::assertSame(200, $response->status, $case);
            self::assertNull($name, $case);
        }

        // A number is a name: the key of a row.
        self::assertSame('42', $this->nameOf($identified(42)));
    }

    public function testAnIdentifierThatIsAnObjectGoesByItsText(): void
    {
        // The key of a row may be an object of its own, as a UUID often is.
        $user = new class () {
            public function getAuthIdentifier(): \Stringable
            {
                return new class () implements \Stringable {
                    public function __toString(): string
                    {
                        return '01JD3V7QZ4';
                    }
                };
            }
        };

        self::assertSame('01JD3V7QZ4', $this->nameOf($user));
    }

    public function testAUserWithoutAnIdentifierIsNotNamedByItsFields(): void
    {
        // A model that was not saved yet has no key. What it is as a text is
        // still all of its fields, and no name.
        $user = new class () implements \Stringable {
            public function getAuthIdentifier(): mixed
            {
                return null;
            }

            public function __toString(): string
            {
                return '{"name":"Ava","email":"ava@example.com"}';
            }
        };

        [$response, $name] = $this->view($user);

        self::assertNull($name);
        self::assertStringNotContainsString('ava@example.com', $response->body);
    }

    public function testTheIdentifierOfSymfonyComesBeforeThatOfLaravel(): void
    {
        // A class may carry both, when an application moved from one to the
        // other. Symfony's is a name by its contract, Laravel's a key.
        $user = new class () {
            public function getAuthIdentifier(): int
            {
                return 7;
            }

            public function getUserIdentifier(): string
            {
                return 'ava';
            }
        };

        self::assertSame('ava', $this->nameOf($user));
    }

    public function testALongOrBrokenIdentifierIsCutAndCleanedLikeAnyName(): void
    {
        $user = new class () {
            public function getUserIdentifier(): string
            {
                return str_repeat('a', 300) . "\xFF";
            }
        };

        self::assertSame(str_repeat('a', 200), $this->nameOf($user));
    }

    public function testAnIdentifierThatCannotBeReadKeepsEveryoneOut(): void
    {
        $log = $this->captureErrorLog();
        $user = new class () {
            public function getUserIdentifier(): string
            {
                throw new \RuntimeException('the user table is gone');
            }
        };

        [$response, $name] = $this->view($user);

        self::assertSame(500, $response->status);
        self::assertSame(['The login check failed'], $this->texts($response, '//h1'));
        self::assertSame('the tool did not run', $name);
        self::assertStringNotContainsString('the user table is gone', $response->body);
        self::assertStringContainsString('the user table is gone', (string) file_get_contents($log));
    }

    public function testWhatAnIdentifierPrintsNeverReachesTheBrowser(): void
    {
        $user = new class () {
            public function getUserIdentifier(): string
            {
                echo 'printed-by-the-user-object';

                return 'ava';
            }
        };
        $this->expectOutputString('');

        [$response, $name] = $this->view($user);

        self::assertSame('ava', $name);
        self::assertStringNotContainsString('printed-by-the-user-object', $response->body);
    }

    public function testNamesAndNumbersAreAsBefore(): void
    {
        self::assertSame('ava', $this->nameOf('ava'));
        self::assertSame('42', $this->nameOf(42));
        self::assertSame('1.5', $this->nameOf(1.5));
        // Logged in, and nobody said who.
        self::assertNull($this->nameOf(true));
        self::assertNull($this->nameOf(['id' => 7]));
    }
}
