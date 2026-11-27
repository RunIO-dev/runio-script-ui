<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\number;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\text;

final class ActionsTest extends ToolTestCase
{
    public function testAButtonIsOnlyTrueOnTheRequestThatClickedIt(): void
    {
        $pressed = null;
        $tool = function () use (&$pressed): void {
            $pressed = button('Refund selected');
        };

        $response = $this->get($tool);
        self::assertFalse($pressed);
        self::assertSame(
            ['Refund selected'],
            $this->texts($response, '//button[@type="submit" and @name="_click" and @value="refund-selected"]'),
        );

        $this->post($tool, ['_click' => 'refund-selected']);
        self::assertTrue($pressed);

        $this->post($tool);
        self::assertFalse($pressed, 'the click is not remembered');

        $this->get($tool);
        self::assertFalse($pressed);
    }

    public function testTwoButtonsWithTheSameLabelNeedTheirOwnKeys(): void
    {
        $orders = [['id' => 7], ['id' => 8]];

        // Numbered keys (refund, refund-2) would follow the position in the list. If the
        // list changed before the click arrived, the click would hit another row.
        $response = $this->get(function () use ($orders): void {
            foreach ($orders as $order) {
                button('Refund');
            }
        });
        self::assertSame(500, $response->status);
        self::assertStringContainsString('&quot;Refund&quot;', $response->body);
        self::assertStringContainsString('key:', $response->body);

        $refunded = [];
        $tool = function () use (&$orders, &$refunded): void {
            foreach ($orders as $order) {
                if (button('Refund', key: 'refund-' . $order['id'])) {
                    $refunded[] = $order['id'];
                }
            }
        };

        // Order 7 disappears between the page view and the click. The click still means order 8.
        $this->get($tool);
        $orders = [['id' => 8]];
        $this->post($tool, ['_click' => 'refund-8']);

        self::assertSame([8], $refunded);
    }

    public function testButtonsThatComeAndGoCanBeFoundAgain(): void
    {
        // The page script puts the keyboard focus back after it swapped the content.
        // It finds the element by its id, so every button that submits has one.
        $tool = function (): void {
            \Runio\ScriptUi\table([['id' => 1, 'name' => 'Ava']]);
            button('Delete', confirm: 'Really?');
        };

        $response = $this->post($tool, ['_click' => 'delete']);

        self::assertSame(1, $this->hits($response, '//button[@id="s-table:0" and @value="table:sort:0"]'));
        self::assertSame(1, $this->hits($response, '//button[@id="s-table:1"]'));
        self::assertSame(1, $this->hits($response, '//button[@id="b-delete"]'));
        self::assertSame('b-delete', $this->attr($response, '//button[@id="b-delete:yes"]', 'data-sui-return'));
        self::assertSame('b-delete', $this->attr($response, '//button[@id="b-delete:no"]', 'data-sui-return'));
    }

    public function testNoKeyGivesAnInputTheIdOfAPartOfATableOrOfAQuestion(): void
    {
        // "table-0" and "delete-yes" are ordinary keys. The first tick box of a table
        // and the yes of a question must not end up with the ids these keys get: a
        // label would then point at the wrong element and the focus would go there.
        $tool = function (): void {
            \Runio\ScriptUi\table([['id' => 1, 'name' => 'Ava']], selectable: true);
            \Runio\ScriptUi\checkbox('Table 0');
            button('Delete', confirm: 'Really?');
            button('Delete yes');
            button('Delete no');
        };

        $response = $this->post($tool, ['_click' => 'delete']);

        $ids = [];
        foreach ($this->xpath($response)->query('//*[@id]') ?: [] as $element) {
            self::assertInstanceOf(\DOMElement::class, $element);
            $ids[] = $element->getAttribute('id');
        }

        self::assertContains('f-table-0', $ids);
        self::assertContains('b-delete-yes', $ids);
        self::assertSame(array_values(array_unique($ids)), $ids, 'no id is used twice');
    }

    public function testAPageViewNeverPressesAButton(): void
    {
        $pressed = null;
        $this->get(function () use (&$pressed): void {
            $pressed = button('Delete');
        }, query: ['_click' => 'delete']);

        self::assertFalse($pressed);
    }

    public function testButtonStyles(): void
    {
        $response = $this->get(function (): void {
            button('Delete', style: 'danger');
            button('Go', style: 'primary');
        });

        self::assertStringContainsString('sui-btn-danger', (string) $this->attr($response, '//button[@value="delete"]', 'class'));
        self::assertStringContainsString('sui-btn-primary', (string) $this->attr($response, '//button[@value="go"]', 'class'));

        $response = $this->get(function (): void {
            button('Go', style: 'fancy');
        });
        self::assertSame(500, $response->status);
    }

    public function testAButtonCanAskBeforeItCounts(): void
    {
        $deleted = 0;
        $tool = function () use (&$deleted): void {
            if (button('Delete', confirm: 'Delete 2 rows?')) {
                $deleted++;
            }
        };

        $response = $this->get($tool);
        self::assertSame(0, $this->hits($response, '//*[@data-confirm]'), 'no question before the click');

        $response = $this->post($tool, ['_click' => 'delete']);
        self::assertSame(0, $deleted, 'the first click only asks');
        self::assertSame(['Delete 2 rows?'], $this->texts($response, '//*[@data-confirm]//p'));
        self::assertSame(['Yes', 'Cancel'], $this->texts($response, '//*[@data-confirm]//button'));

        $yes = (string) $this->attr($response, '//*[@data-confirm]//button[1]', 'value');
        self::assertMatchesRegularExpression('/^delete:yes:[0-9a-f]{12}$/', $yes);

        $response = $this->post($tool, ['_click' => $yes]);
        self::assertSame(1, $deleted);
        self::assertSame(0, $this->hits($response, '//*[@data-confirm]'));
    }

    public function testCancellingTheQuestionDoesNothing(): void
    {
        $deleted = 0;
        $tool = function () use (&$deleted): void {
            if (button('Delete', confirm: 'Delete 2 rows?')) {
                $deleted++;
            }
        };

        $response = $this->post($tool, ['_click' => 'delete']);
        $no = (string) $this->attr($response, '//*[@data-confirm]//button[2]', 'value');
        self::assertSame('delete:no', $no);

        $response = $this->post($tool, ['_click' => $no]);
        self::assertSame(0, $deleted);
        self::assertSame(0, $this->hits($response, '//*[@data-confirm]'));
    }

    public function testAnyOtherInteractionClosesTheQuestion(): void
    {
        $deleted = 0;
        $tool = function () use (&$deleted): void {
            text('Search');
            if (button('Delete', confirm: 'Delete 2 rows?')) {
                $deleted++;
            }
        };

        $this->post($tool, ['_click' => 'delete']);
        $response = $this->post($tool, ['search' => 'something new']);

        self::assertSame(0, $deleted);
        self::assertSame(0, $this->hits($response, '//*[@data-confirm]'));
    }

    public function testAYesOnlyCountsForTheQuestionThatWasShown(): void
    {
        $deleted = 0;
        $tool = function () use (&$deleted): void {
            $count = number('Rows', default: 2);
            if (button('Delete', confirm: "Delete $count rows?")) {
                $deleted += (int) $count;
            }
        };

        $response = $this->post($tool, ['rows' => '2', '_click' => 'delete']);
        $yes = (string) $this->attr($response, '//*[@data-confirm]//button[1]', 'value');

        // The number changes after the question was shown: the old answer must not count.
        $response = $this->post($tool, ['rows' => '3', '_click' => $yes]);

        self::assertSame(0, $deleted);
        self::assertSame(['Delete 3 rows?'], $this->texts($response, '//*[@data-confirm]//p'));
    }

    public function testAPageViewCannotAnswerTheQuestion(): void
    {
        $deleted = 0;
        $tool = function () use (&$deleted): void {
            if (button('Delete', confirm: 'Delete 2 rows?')) {
                $deleted++;
            }
        };

        $response = $this->post($tool, ['_click' => 'delete']);
        $yes = (string) $this->attr($response, '//*[@data-confirm]//button[1]', 'value');

        $this->get($tool, query: ['_click' => $yes]);

        self::assertSame(0, $deleted);
    }

    public function testTheAnswersCanBeWorded(): void
    {
        $response = $this->post(function (): void {
            button('Archive', style: 'danger', confirm: 'Archive everything?', yes: 'Archive', no: 'Keep');
        }, ['_click' => 'archive']);

        self::assertSame(['Archive', 'Keep'], $this->texts($response, '//*[@data-confirm]//button'));
        self::assertStringContainsString(
            'sui-btn-danger',
            (string) $this->attr($response, '//*[@data-confirm]//button[1]', 'class'),
            'the yes button looks like the button that asked',
        );
    }

    public function testAButtonWithoutAQuestionIgnoresAnswers(): void
    {
        $pressed = null;
        $this->post(function () use (&$pressed): void {
            $pressed = button('Delete');
        }, ['_click' => 'delete:yes:0123456789ab']);

        self::assertFalse($pressed);
    }

    public function testAPressedButtonIsReportedToTheAuditLog(): void
    {
        $tool = function (): void {
            text('Customer email');
            if (button('Delete account')) {
                note('gone');
            }
        };

        $this->post($tool, ['customer-email' => 'ava@example.com']);
        self::assertSame([], $this->logged, 'a plain rerun is not an action');

        $this->post($tool, ['customer-email' => 'ava@example.com', '_click' => 'delete-account']);
        self::assertCount(1, $this->logged);

        $entry = $this->logged[0];
        self::assertSame('tester', $entry['user']);
        self::assertSame('tool', $entry['tool']);
        self::assertSame('Delete account', $entry['action']);
        self::assertFalse($entry['confirmed']);
        self::assertSame(['customer-email' => 'ava@example.com'], $entry['inputs']);
        self::assertNull($entry['error']);
        self::assertNull($entry['download'], 'a button hands out no file');
    }

    public function testNothingIsLoggedUntilTheQuestionIsAnswered(): void
    {
        $tool = function (): void {
            if (button('Delete', confirm: 'Sure?')) {
                note('gone');
            }
        };

        $response = $this->post($tool, ['_click' => 'delete']);
        self::assertSame([], $this->logged);

        $yes = (string) $this->attr($response, '//*[@data-confirm]//button[1]', 'value');
        $this->post($tool, ['_click' => $yes]);

        self::assertCount(1, $this->logged);
        self::assertSame('Delete', $this->logged[0]['action']);
        self::assertTrue($this->logged[0]['confirmed']);
    }

    public function testAFailedActionIsLoggedWithItsError(): void
    {
        $response = $this->post(function (): void {
            if (button('Delete')) {
                throw new \RuntimeException('database is down');
            }
        }, ['_click' => 'delete']);

        self::assertSame(500, $response->status);
        self::assertCount(1, $this->logged);
        self::assertSame('database is down', $this->logged[0]['error']);
    }

    public function testRerunReloadsThePageAndKeepsTheMessages(): void
    {
        $saves = 0;
        $tool = function () use (&$saves): void {
            if (button('Save')) {
                $saves++;
                success('Saved.');
                rerun();
            }
            note('Body');
        };

        $response = $this->post($tool, ['_click' => 'save']);
        self::assertSame(303, $response->status);
        self::assertSame('/tool', $response->header('Location'));
        self::assertSame(1, $saves);
        self::assertCount(1, $this->logged, 'the action is still logged');

        $response = $this->get($tool);
        self::assertSame(['Saved.'], $this->texts($response, '//*[contains(@class,"sui-success")]'));
        self::assertSame(1, $saves, 'the reload does not repeat the action');

        $response = $this->get($tool);
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-success")]'), 'the message shows once');
    }

    public function testRerunKeepsWhatTheUserTyped(): void
    {
        $name = null;
        $tool = function () use (&$name): void {
            if (button('Save')) {
                rerun();
            }
            $name = text('Name');
        };

        $this->post($tool, ['_click' => 'save', 'name' => 'Ava']);
        $this->get($tool);

        self::assertSame('Ava', $name);
    }

    public function testRerunOnAPageViewIsAnError(): void
    {
        $response = $this->get(function (): void {
            rerun();
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('rerun()', $response->body);
    }
}
