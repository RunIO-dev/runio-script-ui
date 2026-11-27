<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Config;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\checkbox;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\date_input;
use function Runio\ScriptUi\download;
use function Runio\ScriptUi\expander;
use function Runio\ScriptUi\field_error;
use function Runio\ScriptUi\file_input;
use function Runio\ScriptUi\multiselect;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\number;
use function Runio\ScriptUi\radio;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\select;
use function Runio\ScriptUi\stop;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\tabs;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\textarea;
use function Runio\ScriptUi\title;
use function Runio\ScriptUi\valid;

/**
 * field_error() says what is wrong with what an input holds, right under that
 * input, although the script only finds out further down. valid() says whether
 * anything was found wrong in this run.
 */
final class FieldErrorTest extends ToolTestCase
{
    private const NOTE = '//p[contains(@class,"sui-field-error")]';

    private const FOCUS = '//*[@data-sui-focus]/@id';

    /** Sends the form with a button pressed. */
    private function pressed(callable $tool, string $button, array $fields = [], array $headers = [], array $files = []): Response
    {
        return $this->post($tool, ['_click' => $button] + $fields, $headers, $files);
    }

    /** What a page that failed says about it. The tests run with debug switched on. */
    private function failure(Response $response): string
    {
        self::assertSame(500, $response->status);

        return $this->texts($response, '//div[contains(@class,"sui-error")]')[0] ?? '';
    }

    /** @return array<array-key,mixed> what the tool keeps for its next page view */
    private function saved(): array
    {
        $states = $this->session->get('state');

        return is_array($states) ? (array_values($states)[0] ?? []) : [];
    }

    // --------------------------------------------------------- note and marks

    public function testTheMessageStandsUnderItsFieldAndTheFieldSaysThatItIsWrong(): void
    {
        $tool = function (): void {
            $amount = number('Amount');
            text('Email');
            if (button('Refund') && ($amount === null || $amount <= 0)) {
                field_error('Amount', 'Enter an amount above 0.');
            }
        };

        $response = $this->pressed($tool, 'refund', ['amount' => '-5']);

        self::assertSame(200, $response->status);
        // The note is the next thing after the field it belongs to.
        self::assertSame('e-amount', $this->attr($response, '//input[@id="f-amount"]/following-sibling::*[1]', 'id'));
        self::assertSame(['Error: Enter an amount above 0.'], $this->texts($response, self::NOTE));
        self::assertSame('sui-field-error', $this->attr($response, self::NOTE, 'class'));
        self::assertSame('auto', $this->attr($response, self::NOTE, 'dir'));
        // The word in front is for somebody who cannot see that the note is red. It is not shown.
        self::assertSame(['Error:'], $this->texts($response, self::NOTE . '/span[@class="sui-sr"]'));

        // The field says that it is wrong, and names the note that says why.
        self::assertSame('true', $this->attr($response, '//input[@id="f-amount"]', 'aria-invalid'));
        self::assertSame('e-amount', $this->attr($response, '//input[@id="f-amount"]', 'aria-describedby'));
        self::assertSame('sui-field sui-field-invalid', $this->attr($response, '//input[@id="f-amount"]/..', 'class'));
        // What the user typed is still there to be corrected.
        self::assertSame('-5', $this->attr($response, '//input[@id="f-amount"]', 'value'));

        // The field next to it is as it always was.
        self::assertNull($this->attr($response, '//input[@id="f-email"]', 'aria-invalid'));
        self::assertNull($this->attr($response, '//input[@id="f-email"]', 'aria-describedby'));
        self::assertSame('sui-field', $this->attr($response, '//input[@id="f-email"]/..', 'class'));
    }

    public function testAPageWithoutAMessageIsDrawnAsBefore(): void
    {
        $response = $this->get(function (): void {
            number('Amount');
            text('Email');
            textarea('Reason');
            select('Status', ['paid', 'shipped']);
            date_input('Day');
            checkbox('Agree');
            radio('Plan', ['Free', 'Pro']);
            multiselect('Tags', ['new', 'late']);
            file_input('Report');
            button('Refund');
        });

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, self::NOTE));
        self::assertSame(0, $this->hits(
            $response,
            '//*[@aria-invalid] | //*[@data-sui-focus] | //*[@autofocus] | //*[contains(@class,"sui-field-invalid")]',
        ));
        // Only the file field has a note under it: what it takes.
        self::assertSame(['l-report'], $this->texts($response, '//*[@aria-describedby]/@aria-describedby'));
        self::assertSame(9, $this->hits($response, '//*[contains(concat(" ", @class, " "), " sui-field ")]'));
        self::assertStringNotContainsString('<!--sui', $response->body, 'every place that was kept is filled');
    }

    public function testATextANumberAListAndADateAreMarkedTheSameWay(): void
    {
        $tool = function (): void {
            text('Name');
            textarea('Reason');
            number('Amount');
            select('Status', ['paid', 'shipped']);
            date_input('Day');
            if (button('Save')) {
                foreach (['Name', 'Reason', 'Amount', 'Status', 'Day'] as $label) {
                    field_error($label, $label . ' is wrong.');
                }
            }
        };

        $response = $this->pressed($tool, 'save', ['name' => 'Ava', 'reason' => 'late', 'amount' => '7', 'status' => 'shipped', 'day' => '2026-03-02']);

        foreach (['name' => 'input', 'reason' => 'textarea', 'amount' => 'input', 'status' => 'select', 'day' => 'input'] as $key => $tag) {
            $control = '//' . $tag . '[@id="f-' . $key . '"]';
            self::assertSame('true', $this->attr($response, $control, 'aria-invalid'), $key);
            self::assertSame('e-' . $key, $this->attr($response, $control, 'aria-describedby'), $key);
            self::assertSame('e-' . $key, $this->attr($response, $control . '/following-sibling::*[1]', 'id'), $key);
            self::assertSame('sui-field sui-field-invalid', $this->attr($response, $control . '/..', 'class'), $key);
        }
        self::assertSame(
            ['Error: Name is wrong.', 'Error: Reason is wrong.', 'Error: Amount is wrong.', 'Error: Status is wrong.', 'Error: Day is wrong.'],
            $this->texts($response, self::NOTE),
        );

        // Every field still holds what was sent.
        self::assertSame('Ava', $this->attr($response, '//input[@id="f-name"]', 'value'));
        self::assertSame(['late'], $this->texts($response, '//textarea[@id="f-reason"]'));
        self::assertSame('7', $this->attr($response, '//input[@id="f-amount"]', 'value'));
        self::assertSame(['shipped'], $this->texts($response, '//select[@id="f-status"]/option[@selected]'));
        self::assertSame('2026-03-02', $this->attr($response, '//input[@id="f-day"]', 'value'));
        self::assertStringNotContainsString('<!--sui', $response->body);
    }

    public function testATickBoxIsMarked(): void
    {
        $tool = function (): void {
            $agreed = checkbox('I have read the terms', help: 'They are short.');
            if (button('Sign up') && !$agreed) {
                field_error('I have read the terms', 'Tick the box to go on.');
            }
        };

        $response = $this->pressed($tool, 'sign-up');
        $box = '//input[@id="f-i-have-read-the-terms"]';
        $field = '//div[contains(@class,"sui-field-check")]';

        self::assertSame('true', $this->attr($response, $box, 'aria-invalid'));
        self::assertSame('e-i-have-read-the-terms h-i-have-read-the-terms', $this->attr($response, $box, 'aria-describedby'));
        self::assertSame('sui-field sui-field-check sui-field-invalid', $this->attr($response, $field, 'class'));
        // Under the box and its label, and above the help text.
        self::assertSame(['label', 'input', 'p', 'p'], array_map(
            static fn (\DOMNode $node): string => $node->nodeName,
            iterator_to_array($this->xpath($response)->query($field . '/*') ?: []),
        ));
        self::assertSame(['e-i-have-read-the-terms', 'h-i-have-read-the-terms'], $this->texts($response, $field . '/p/@id'));
        self::assertSame(['Error: Tick the box to go on.', 'They are short.'], $this->texts($response, $field . '/p'));

        // Ticked, the box is right.
        $ticked = $this->pressed($tool, 'sign-up', ['i-have-read-the-terms' => '1']);
        self::assertNull($this->attr($ticked, $box, 'aria-invalid'));
        self::assertSame('h-i-have-read-the-terms', $this->attr($ticked, $box, 'aria-describedby'));
        self::assertSame('sui-field sui-field-check', $this->attr($ticked, $field, 'class'));
    }

    public function testAGroupOfRadioButtonsIsDescribedByTheMessage(): void
    {
        $tool = function (): void {
            $speed = radio('Shipping', ['Standard', 'Express'], help: 'Express costs more.');
            if (button('Order') && $speed === 'Express') {
                field_error('Shipping', 'Express does not reach this address.');
            }
        };

        $response = $this->pressed($tool, 'order', ['shipping' => 'Express']);
        $group = '//fieldset[@id="f-shipping"]';

        self::assertSame('e-shipping h-shipping', $this->attr($response, $group, 'aria-describedby'));
        self::assertSame('sui-field sui-field-choices sui-field-invalid', $this->attr($response, $group, 'class'));
        // Neither a group nor a radio button may call itself invalid. The note says it for them.
        self::assertSame(0, $this->hits($response, $group . '/@aria-invalid | ' . $group . '//input/@aria-invalid'));
        // Under the options, and above the help text.
        self::assertSame('e-shipping', $this->attr($response, $group . '/div[contains(@class,"sui-choices")]/following-sibling::*[1]', 'id'));
        self::assertSame(['e-shipping', 'h-shipping'], $this->texts($response, $group . '/p/@id'));
        self::assertSame(['Express'], $this->texts($response, $group . '//label[input/@checked]'), 'the choice is kept');

        $fine = $this->pressed($tool, 'order', ['shipping' => 'Standard']);
        self::assertSame('h-shipping', $this->attr($fine, $group, 'aria-describedby'));
        self::assertSame('sui-field sui-field-choices', $this->attr($fine, $group, 'class'));
    }

    public function testEveryTickBoxOfAGroupSaysThatTheGroupIsWrong(): void
    {
        $tool = function (): void {
            $status = multiselect('Status', ['paid', 'shipped', 'refunded'], horizontal: true);
            if (button('Export') && $status === []) {
                field_error('Status', 'Tick at least one status.');
            }
        };

        $response = $this->pressed($tool, 'export');
        $group = '//fieldset[@id="f-status"]';

        self::assertSame('e-status', $this->attr($response, $group, 'aria-describedby'));
        self::assertNull($this->attr($response, $group, 'aria-invalid'));
        self::assertSame('sui-field sui-field-choices sui-field-wide sui-field-invalid', $this->attr($response, $group, 'class'));
        self::assertSame(3, $this->hits($response, $group . '//input[@type="checkbox" and @aria-invalid="true"]'));
        // The note comes after the options and after the field that says the group was on the page.
        self::assertSame(['e-status'], $this->texts($response, $group . '/*[last()]/@id'));

        $fine = $this->pressed($tool, 'export', ['status' => ['paid']]);
        self::assertSame(0, $this->hits($fine, $group . '//input[@aria-invalid] | ' . self::NOTE));
    }

    public function testAFileFieldIsMarked(): void
    {
        $tool = function (): void {
            $file = file_input('Report', types: ['csv'], help: 'The export of last month.');
            if (button('Import') && $file === null) {
                field_error('Report', 'Choose a file first.');
            }
        };

        $response = $this->pressed($tool, 'import');
        $input = '//input[@id="f-report"]';
        $field = '//div[contains(@class,"sui-field-file")]';

        self::assertSame('true', $this->attr($response, $input, 'aria-invalid'));
        // What is wrong is read first, then what the field takes, then the help text.
        self::assertSame('e-report l-report h-report', $this->attr($response, $input, 'aria-describedby'));
        self::assertSame('sui-field sui-field-file sui-field-invalid', $this->attr($response, $field, 'class'));
        self::assertSame(['Error: Choose a file first.'], $this->texts($response, '//p[@id="e-report"]'));
        self::assertSame(['The export of last month.'], $this->texts($response, '//p[@id="h-report"]'));
        self::assertSame(1, $this->hits($response, $field . '/p[@id="e-report"]'));

        // Without a message the field is described by its limits and its help text.
        $plain = $this->get($tool);
        self::assertNull($this->attr($plain, $input, 'aria-invalid'));
        self::assertSame('l-report h-report', $this->attr($plain, $input, 'aria-describedby'));
        self::assertSame('sui-field sui-field-file', $this->attr($plain, $field, 'class'));
    }

    public function testAFileThatWasTurnedDownAndAMessageOfTheToolShareOneNote(): void
    {
        $tool = function (): void {
            $file = file_input('Report', maxSize: 1);
            if ($file === null) {
                field_error('Report', 'Still no file.');
            }
        };

        $response = $this->post($tool, files: ['report' => $this->arrived('big.csv', 'xx')]);

        self::assertSame(1, $this->hits($response, self::NOTE));
        $said = $this->texts($response, self::NOTE)[0];
        self::assertStringStartsWith('Error: "big.csv" is larger than ', $said);
        self::assertStringEndsWith('Still no file.', $said);
        self::assertSame(1, $this->hits($response, self::NOTE . '/br'));
        self::assertSame('true', $this->attr($response, '//input[@id="f-report"]', 'aria-invalid'));
        // The file was sent a moment ago: what became of it is news, button or not.
        self::assertSame('alert', $this->attr($response, self::NOTE, 'role'));
        self::assertSame('', $this->attr($response, self::NOTE, 'data-sui-result'));
    }

    public function testAFileThatWasTurnedDownMarksItsFieldLikeAnythingElseThatIsWrong(): void
    {
        $tool = function (): void {
            file_input('Report', maxSize: 1);
        };

        $response = $this->post($tool, files: ['report' => $this->arrived('big.csv', 'xx')]);

        // The library found this and not the tool. For the user that is no difference.
        self::assertSame('true', $this->attr($response, '//input[@id="f-report"]', 'aria-invalid'));
        self::assertSame('sui-field sui-field-file sui-field-invalid', $this->attr($response, '//div[contains(@class,"sui-field-file")]', 'class'));
        self::assertSame('e-report l-report', $this->attr($response, '//input[@id="f-report"]', 'aria-describedby'));
        self::assertSame(['Error:'], $this->texts($response, self::NOTE . '/span[@class="sui-sr"]'));
        // The file was sent a moment ago: what became of it is news, button or not. It is read out,
        // and a page that runs again by itself waits until the user has read it. See refresh().
        self::assertSame('alert', $this->attr($response, self::NOTE, 'role'));
        self::assertSame('', $this->attr($response, self::NOTE, 'data-sui-result'));

        // It is said once. The next run starts anew.
        $next = $this->post($tool);
        self::assertSame(0, $this->hits($next, self::NOTE . ' | //*[@aria-invalid] | //*[contains(@class,"sui-field-invalid")]'));
    }

    public function testAFileFieldBringsTheWordForThePageScriptInTheLanguageOfThePage(): void
    {
        // The page script turns a file down before it is sent, and says so the way the server would.
        $english = $this->get(function (): void {
            file_input('Report');
        });
        self::assertSame('Error:', $this->attr($english, '//input[@type="file"]', 'data-sui-error'));

        $arabic = $this->get(function (): void {
            title('الطلبات');
            file_input('التقرير');
        });
        self::assertSame('خطأ:', $this->attr($arabic, '//input[@type="file"]', 'data-sui-error'));
    }

    public function testHelpTextDescribesItsFieldAlsoWhenNothingIsWrong(): void
    {
        $tool = function (): void {
            $amount = number('Amount', help: 'In euros.');
            select('Status', ['paid'], help: 'Of the order.');
            text('Plain');
            if ($amount === null) {
                field_error('Amount', 'Enter an amount.');
            }
        };

        $wrong = $this->get($tool);
        self::assertSame('e-amount h-amount', $this->attr($wrong, '//input[@id="f-amount"]', 'aria-describedby'), 'what is wrong is read first');
        self::assertSame(['e-amount', 'h-amount'], $this->texts($wrong, '//input[@id="f-amount"]/following-sibling::p/@id'));
        self::assertSame('h-status', $this->attr($wrong, '//select[@id="f-status"]', 'aria-describedby'));
        self::assertSame(['Of the order.'], $this->texts($wrong, '//p[@id="h-status"]'));
        self::assertSame('sui-help', $this->attr($wrong, '//p[@id="h-status"]', 'class'));
        self::assertNull($this->attr($wrong, '//input[@id="f-plain"]', 'aria-describedby'));

        $right = $this->post($tool, ['amount' => '5']);
        self::assertSame('h-amount', $this->attr($right, '//input[@id="f-amount"]', 'aria-describedby'));
        self::assertSame(['In euros.'], $this->texts($right, '//input[@id="f-amount"]/following-sibling::p'));
    }

    public function testSeveralMessagesForOneFieldStandUnderEachOther(): void
    {
        $response = $this->get(function (): void {
            text('Password');
            field_error('Password', 'Use at least 12 characters.');
            field_error('Password', 'Use a digit.');
            // Checked twice, said once.
            field_error('Password', 'Use at least 12 characters.');
        });

        self::assertSame(1, $this->hits($response, self::NOTE));
        self::assertStringContainsString("</span> Use at least 12 characters.<br>\nUse a digit.</p>", $response->body);
        self::assertSame('e-password', $this->attr($response, '//input[@id="f-password"]', 'aria-describedby'));
    }

    public function testAMessageIsTextAndNeverMarkup(): void
    {
        $response = $this->get(function (): void {
            text('Name');
            field_error('Name', '<img src=x onerror=alert(1)> & "quoted"' . "\nsecond line");
        });

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, '//img'));
        self::assertStringContainsString("&lt;img src=x onerror=alert(1)&gt; &amp; &quot;quoted&quot;<br>\nsecond line</p>", $response->body);
    }

    public function testAMessageHasToSaySomething(): void
    {
        foreach (['', '   ', "\n\t"] as $message) {
            $response = $this->get(function () use ($message): void {
                text('Name');
                field_error('Name', $message);
            });

            self::assertStringContainsString('field_error() needs a message that tells the user what to change.', $this->failure($response));
        }
    }

    public function testALongMessageIsCut(): void
    {
        // A tool that repeats what the user typed, and a user who pasted a whole file.
        $typed = str_repeat('ä', 3000);
        $response = $this->pressed(function () use ($typed): void {
            text('Code');
            if (button('Save')) {
                field_error('Code', '"' . $typed . '" is not a code.');
                field_error('Code', str_repeat('b', 500));
            }
        }, 'save');

        self::assertSame(200, $response->status);
        [$cut, $whole] = $this->logged[0]['field_errors']['code'];
        self::assertSame(500, mb_strlen($cut, 'UTF-8'));
        self::assertSame('"' . str_repeat('ä', 498) . '…', $cut);
        self::assertSame(str_repeat('b', 500), $whole, 'five hundred characters fit');
        // The page says what the log says.
        self::assertStringContainsString('</span> &quot;' . str_repeat('ä', 498) . "…<br>\n" . $whole . '</p>', $response->body);
    }

    public function testAFieldSaysNoMoreThanTenThings(): void
    {
        $response = $this->pressed(function (): void {
            text('Code');
            text('Name');
            if (button('Save')) {
                for ($n = 1; $n <= 12; $n++) {
                    field_error('Code', 'Problem ' . $n . '.');
                }
                field_error('Name', 'Enter a name.');
            }
        }, 'save');

        self::assertCount(10, $this->logged[0]['field_errors']['code']);
        self::assertSame('Problem 10.', $this->logged[0]['field_errors']['code'][9]);
        self::assertSame(9, $this->hits($response, '//p[@id="e-code"]/br'));
        self::assertSame(['Enter a name.'], $this->logged[0]['field_errors']['name'], 'the limit is for one field');
    }

    public function testAMessageThatIsNotProperTextDoesNotBreakTheLog(): void
    {
        $response = $this->pressed(function (): void {
            text('Name');
            if (button('Save')) {
                field_error('Name', "  cut off \xC3 here  ");
            }
        }, 'save');

        self::assertSame(200, $response->status);
        $logged = $this->logged[0]['field_errors']['name'][0];
        self::assertTrue(mb_check_encoding($logged, 'UTF-8'));
        self::assertStringStartsWith('cut off ', $logged);
        self::assertStringEndsWith(' here', $logged);
    }

    // ------------------------------------------------------ finding the field

    public function testAFieldIsFoundByItsLabelOrByItsKey(): void
    {
        $marks = static fn (string $name): \Closure => static function () use ($name): void {
            number('Amount', key: 'amt');
            text('Customer email');
            text('2024');
            text('Say "hi" & <wave>');
            field_error($name, 'Wrong.');
        };

        $found = [
            'Amount' => 'e-amt',
            'amt' => 'e-amt',
            'Customer email' => 'e-customer-email',
            'customer-email' => 'e-customer-email',
            '2024' => 'e-2024',
            'Say "hi" & <wave>' => 'e-say-hi-wave',
        ];
        foreach ($found as $name => $note) {
            $response = $this->get($marks((string) $name));
            self::assertSame(200, $response->status, (string) $name);
            self::assertSame([$note], $this->texts($response, self::NOTE . '/@id'), (string) $name);
        }
    }

    public function testANameIsTheLabelAsItIsWrittenOrTheKey(): void
    {
        $marks = static fn (string $name): \Closure => static function () use ($name): void {
            number('Amount', key: 'amt');
            field_error($name, 'Wrong.');
        };

        // Nothing is guessed: a name that is almost right would one day mark the wrong field.
        foreach (['amount', 'AMOUNT', 'Amount ', 'Amt'] as $name) {
            self::assertStringContainsString('no input called "' . $name . '"', $this->failure($this->get($marks($name))), $name);
        }
    }

    public function testAKeyCountsForMoreThanALabel(): void
    {
        $marks = static fn (string $name): \Closure => static function () use ($name): void {
            text('Work email', key: 'email');
            text('email', key: 'private');
            field_error($name, 'Wrong.');
        };

        self::assertSame(['e-email'], $this->texts($this->get($marks('email')), self::NOTE . '/@id'));
        // The field that is labelled "email" is reached by its own key.
        self::assertSame(['e-private'], $this->texts($this->get($marks('private')), self::NOTE . '/@id'));
    }

    public function testTwoInputsWithOneLabelAreToldApartByTheirKeys(): void
    {
        $marks = static fn (string $name): \Closure => static function () use ($name): void {
            text('Name', key: 'first');
            number('Amount');
            text('Name', key: 'last');
            field_error($name, 'Wrong.');
        };

        self::assertStringContainsString(
            'field_error(): 2 inputs on this page are called "Name". Say which one by its key: "first" or "last".',
            $this->failure($this->get($marks('Name'))),
        );
        self::assertSame(['e-last'], $this->texts($this->get($marks('last')), self::NOTE . '/@id'));
    }

    public function testAnInputIsMarkedAfterItWasDrawn(): void
    {
        $said = $this->failure($this->get(function (): void {
            text('Email');
            textarea('Notes', key: 'remarks');
            field_error('Amount', 'Wrong.');
            number('Amount');
        }));

        self::assertStringContainsString('field_error(): no input called "Amount" was drawn before this call.', $said);
        self::assertStringContainsString('in the same run', $said);
        self::assertStringContainsString('Drawn so far: "Email", "Notes".', $said);

        $nothing = $this->failure($this->get(function (): void {
            note('Nothing to fill in.');
            field_error('Amount', 'Wrong.');
        }));
        self::assertStringContainsString('No input was drawn so far.', $nothing);
    }

    public function testALongListOfInputsIsNotPrintedInFull(): void
    {
        $said = $this->failure($this->get(function (): void {
            for ($n = 1; $n <= 13; $n++) {
                text('Field ' . $n);
            }
            field_error('Field 14', 'Wrong.');
        }));

        self::assertStringContainsString('"Field 10" and 3 more.', $said);
        self::assertStringNotContainsString('"Field 11"', $said);

        // Ten are named, all of them.
        $ten = $this->failure($this->get(function (): void {
            for ($n = 1; $n <= 10; $n++) {
                text('Field ' . $n);
            }
            field_error('Field 11', 'Wrong.');
        }));
        self::assertStringContainsString('"Field 9", "Field 10".', $ten);
        self::assertStringNotContainsString('more', $ten);
    }

    public function testLabelsThatAreNumbersAreComparedAsText(): void
    {
        $response = $this->get(function (): void {
            // To PHP "10" and "1e1" are the same number. They are two labels.
            text('1e1', key: 'short');
            text('10', key: 'long');
            field_error('10', 'Wrong.');
        });

        self::assertSame(200, $response->status);
        self::assertSame(['e-long'], $this->texts($response, self::NOTE . '/@id'));
    }

    public function testOnlyAnInputCanBeMarked(): void
    {
        $tools = [
            'Refund' => function (): void {
                button('Refund');
                field_error('Refund', 'Wrong.');
            },
            'go_now' => function (): void {
                // A key that no label makes: the name itself is looked up.
                button('Refund now', key: 'go_now');
                field_error('go_now', 'Wrong.');
            },
            'Export' => function (): void {
                download('Export', 'a,b', 'export.csv');
                field_error('Export', 'Wrong.');
            },
            'Details' => function (): void {
                expander('Details', static function (): void {
                    note('inside');
                });
                field_error('Details', 'Wrong.');
            },
            'table' => function (): void {
                table([['id' => 1]], selectable: true);
                field_error('table', 'Wrong.');
            },
            'tabs' => function (): void {
                tabs(['One' => static function (): void {
                    note('1');
                }]);
                field_error('tabs', 'Wrong.');
            },
        ];

        foreach ($tools as $name => $tool) {
            $said = $this->failure($this->get($tool));
            self::assertStringContainsString('field_error(): "' . $name . '" is not an input.', $said, $name);
            self::assertStringContainsString('use error()', $said, $name);
        }
    }

    // ---------------------------------------------------------------- valid()

    public function testValidSaysWhetherAnythingWasFoundWrongSoFar(): void
    {
        $said = [];
        $tool = function () use (&$said): void {
            $name = text('Name');
            $said[] = valid();
            if ($name === '') {
                field_error('Name', 'Enter a name.');
            }
            $said[] = valid();
        };

        $this->get($tool);
        self::assertSame([true, false], $said);

        // Every run starts anew.
        $said = [];
        $response = $this->post($tool, ['name' => 'Ava']);
        self::assertSame([true, true], $said);
        self::assertSame(0, $this->hits($response, self::NOTE));
    }

    public function testTheWorkIsOnlyDoneWhenNothingIsWrong(): void
    {
        $refunded = [];
        $tool = function () use (&$refunded): void {
            $amount = number('Amount');
            $email = text('Email');

            if (button('Refund')) {
                if ($amount === null || $amount <= 0) {
                    field_error('Amount', 'Enter an amount above 0.');
                }
                if (!str_contains($email, '@')) {
                    field_error('Email', 'Enter an email address.');
                }
                if (valid()) {
                    $refunded[] = [$email, $amount];
                    success('Refunded.');
                }
            }
        };

        $wrong = $this->pressed($tool, 'refund', ['amount' => '-5', 'email' => 'ava']);
        self::assertSame([], $refunded);
        self::assertSame(['Error: Enter an amount above 0.', 'Error: Enter an email address.'], $this->texts($wrong, self::NOTE));
        self::assertSame(0, $this->hits($wrong, '//div[contains(@class,"sui-success")]'));

        $right = $this->pressed($tool, 'refund', ['amount' => '12.5', 'email' => 'ava@example.com']);
        self::assertSame([['ava@example.com', 12.5]], $refunded);
        self::assertSame(0, $this->hits($right, self::NOTE));
        self::assertSame(['Refunded.'], $this->texts($right, '//div[contains(@class,"sui-success")]'));
    }

    public function testAFileThatWasTurnedDownHoldsTheWorkBackToo(): void
    {
        $refunded = [];
        $said = [];
        $tool = function () use (&$refunded, &$said): void {
            $receipt = null;
            expander('Attachment', static function () use (&$receipt): void {
                $receipt = file_input('Receipt', maxSize: 1);
            });
            $said[] = valid();

            if (button('Refund') && valid()) {
                $refunded[] = $receipt?->name;
                success('Refunded.');
            }
        };

        // Without the page script a file travels with the button. The user believes
        // that it is attached, and the tool has nothing to say against an empty field.
        $response = $this->pressed($tool, 'refund', ['_open' => ['attachment' => '0']], files: ['receipt' => $this->arrived('big.jpg', 'xx')]);

        self::assertSame([false], $said);
        self::assertSame([], $refunded, 'nothing is done while the page shows something wrong');
        self::assertSame(0, $this->hits($response, '//div[contains(@class,"sui-success")]'));
        self::assertStringStartsWith('Error: "big.jpg" is larger than ', $this->texts($response, self::NOTE)[0]);
        // The user is taken to it, like to anything else that is wrong.
        self::assertSame(['f-receipt'], $this->texts($response, self::FOCUS));
        self::assertSame('', $this->attr($response, '//details[summary/@id="x-attachment"]', 'open'));
        // And whoever reads the log sees why the press did nothing.
        self::assertSame(['receipt'], array_keys($this->logged[0]['field_errors']));
        self::assertStringStartsWith('"big.jpg" is larger than ', $this->logged[0]['field_errors']['receipt'][0]);

        // The user read it and presses again: now the field holds nothing that is wrong.
        $again = $this->pressed($tool, 'refund');
        self::assertSame([false, true], $said);
        self::assertSame([null], $refunded);
        self::assertSame(0, $this->hits($again, self::NOTE));
        self::assertSame([], $this->logged[1]['field_errors']);
    }

    public function testNothingIsRememberedOfAMessage(): void
    {
        $tool = function (): void {
            text('Name');
            if (button('Save')) {
                field_error('Name', 'A message that is found nowhere else.');
            }
        };

        $this->pressed($tool, 'save', ['name' => 'Ava']);

        self::assertSame(['name' => 'Ava'], $this->saved());
        self::assertStringNotContainsString('found nowhere else', serialize($this->session->get('state')) . serialize($this->session->get('flash')));

        // The page is loaded again: no button is pressed, so nothing is checked.
        $again = $this->get($tool);
        self::assertSame(0, $this->hits($again, self::NOTE . ' | //*[@aria-invalid]'));
        self::assertSame('Ava', $this->attr($again, '//input[@id="f-name"]', 'value'));
    }

    public function testAMessageDoesNotOutliveRerun(): void
    {
        $tool = function (): void {
            text('Name');
            if (button('Save')) {
                field_error('Name', 'Wrong.');
                rerun();
            }
        };

        self::assertSame(303, $this->pressed($tool, 'save')->status);

        $after = $this->get($tool);
        self::assertSame(200, $after->status);
        self::assertSame(0, $this->hits($after, self::NOTE));
    }

    // ------------------------------------------------------------------ focus

    public function testAfterAPressedButtonTheFirstWrongFieldOnThePageGetsTheFocus(): void
    {
        $tool = function (): void {
            text('Name');
            text('Email');
            number('Amount');
            if (button('Save')) {
                // Marked from the bottom up: what counts is the place on the page.
                field_error('Amount', 'Wrong.');
                field_error('Email', 'Wrong.');
            }
        };

        $page = $this->pressed($tool, 'save');
        self::assertSame(['f-email'], $this->texts($page, self::FOCUS));
        // Without the page script the browser itself puts the focus there.
        self::assertSame(['f-email'], $this->texts($page, '//*[@autofocus]/@id'));

        // One note is read out: that of the field with the focus. Five notes read
        // out at once are noise, and the others are read when the user gets to them.
        self::assertSame(['e-email'], $this->texts($page, self::NOTE . '[@role]/@id'));
        self::assertSame('alert', $this->attr($page, '//p[@id="e-email"]', 'role'));
        // All of them are news. A page that runs again by itself leaves them until they were read.
        self::assertSame(2, $this->hits($page, self::NOTE . '[@data-sui-result]'));

        // The page script asked. It moves the focus itself: a browser does not
        // follow "autofocus" in content that is put into an open page.
        $part = $this->pressed($tool, 'save', [], ['X-Script-Ui' => 'fragment']);
        self::assertSame(['f-email'], $this->texts($part, self::FOCUS));
        self::assertSame(0, $this->hits($part, '//*[@autofocus]'));
        self::assertSame(['e-email'], $this->texts($part, self::NOTE . '[@role="alert"]/@id'));
        self::assertSame(2, $this->hits($part, self::NOTE . '[@data-sui-result]'));
    }

    public function testWithoutAPressedButtonNothingTakesTheFocus(): void
    {
        $tool = function (): void {
            $email = text('Email');
            if ($email !== '' && !str_contains($email, '@')) {
                field_error('Email', 'This is not an email address.');
            }
            button('Save');
        };

        // The user left the field. The page runs, and they are somewhere else already.
        $left = $this->post($tool, ['email' => 'ava']);
        // A page that runs again by itself presses nothing either.
        $own = $this->post($tool, ['email' => 'ava', '_click' => 'save'], ['X-Script-Ui' => 'refresh']);
        // And a page that is loaded draws what was saved.
        $loaded = $this->get($tool);

        foreach (['left the field' => $left, 'refresh' => $own, 'loaded' => $loaded] as $case => $response) {
            self::assertSame(['Error: This is not an email address.'], $this->texts($response, self::NOTE), $case);
            self::assertSame('true', $this->attr($response, '//input[@id="f-email"]', 'aria-invalid'), $case);
            self::assertSame(0, $this->hits($response, '//*[@data-sui-focus] | //*[@autofocus]'), $case);
            // Nor is it read out over whatever they do now. They hear it when they come back to the field.
            self::assertNull($this->attr($response, self::NOTE, 'role'), $case);
            self::assertNull($this->attr($response, self::NOTE, 'data-sui-result'), $case);
        }
    }

    public function testTheCheckMayStandAboveTheButton(): void
    {
        $saved = 0;
        $tool = function () use (&$saved): void {
            $email = text('Email');
            // Checked on every run, before anybody knows whether a button was pressed.
            if (!str_contains($email, '@')) {
                field_error('Email', 'Enter an email address.');
            }
            if (button('Save') && valid()) {
                $saved++;
            }
        };

        $response = $this->pressed($tool, 'save', ['email' => 'ava']);

        self::assertSame(0, $saved);
        self::assertSame(['f-email'], $this->texts($response, self::FOCUS));
        self::assertSame('alert', $this->attr($response, self::NOTE, 'role'));
        self::assertSame('', $this->attr($response, self::NOTE, 'data-sui-result'));
    }

    public function testInAGroupTheFocusGoesToAnOption(): void
    {
        $marks = static fn (string $label): \Closure => static function () use ($label): void {
            radio('Shipping', ['Standard', 'Express', 'Pickup']);
            multiselect('Status', ['paid', 'shipped']);
            if (button('Save')) {
                field_error($label, 'Wrong.');
            }
        };

        // Radio buttons: the one that is chosen. It is the one the keyboard reaches.
        $radio = $this->pressed($marks('Shipping'), 'save', ['shipping' => 'Express']);
        self::assertSame(1, $this->hits($radio, '//*[@data-sui-focus]'));
        self::assertSame(['Express'], $this->texts($radio, '//fieldset[@id="f-shipping"]//label[input[@data-sui-focus and @autofocus and @checked]]'));

        // Tick boxes: the first, whatever is ticked.
        $boxes = $this->pressed($marks('Status'), 'save', ['status' => ['shipped']]);
        self::assertSame(1, $this->hits($boxes, '//*[@data-sui-focus]'));
        self::assertSame(['paid'], $this->texts($boxes, '//fieldset[@id="f-status"]//label[input[@data-sui-focus and @autofocus]]'));
    }

    public function testAFieldThatCannotTakeTheFocusLeavesItToTheNext(): void
    {
        $tool = function (): void {
            select('Customer', []);
            radio('Plan', []);
            multiselect('Tags', []);
            text('Name');
            checkbox('Agree');
            if (button('Save')) {
                foreach (['Customer', 'Plan', 'Tags', 'Name', 'Agree'] as $label) {
                    field_error($label, 'Wrong.');
                }
            }
        };

        $response = $this->pressed($tool, 'save');

        self::assertSame(['f-name'], $this->texts($response, self::FOCUS));
        self::assertSame(['e-customer', 'e-plan', 'e-tags', 'e-name', 'e-agree'], $this->texts($response, self::NOTE . '/@id'), 'every note is there all the same');
        // The note that is read out goes with the focus.
        self::assertSame(['e-name'], $this->texts($response, self::NOTE . '[@role="alert"]/@id'));
    }

    public function testWhenNoWrongFieldCanTakeTheFocusTheFirstNoteIsReadOut(): void
    {
        $response = $this->pressed(function (): void {
            select('Customer', []);
            radio('Plan', []);
            if (button('Save')) {
                field_error('Plan', 'No plan can be chosen yet.');
                field_error('Customer', 'There is no customer yet.');
            }
        }, 'save');

        self::assertSame(0, $this->hits($response, '//*[@data-sui-focus] | //*[@autofocus]'));
        // Nothing takes the user to a note, so one of them has to speak up: the first on the page.
        self::assertSame(['e-customer'], $this->texts($response, self::NOTE . '[@role="alert"]/@id'));
        self::assertSame(2, $this->hits($response, self::NOTE . '[@data-sui-result]'));
    }

    public function testAFileThatWasTurnedDownIsReadOutWhereverTheFocusGoes(): void
    {
        $response = $this->pressed(function (): void {
            text('Name');
            file_input('Report', maxSize: 1);
            number('Amount');
            if (button('Save')) {
                field_error('Amount', 'Enter an amount.');
                field_error('Name', 'Enter a name.');
            }
        }, 'save', files: ['report' => $this->arrived('big.csv', 'xx')]);

        self::assertSame(['f-name'], $this->texts($response, self::FOCUS));
        // The file does not come back with the next press. Whoever misses this note
        // now would never learn that the file was not taken.
        self::assertSame(['e-name', 'e-report'], $this->texts($response, self::NOTE . '[@role="alert"]/@id'));
        self::assertSame(3, $this->hits($response, self::NOTE . '[@data-sui-result]'));
    }

    public function testAKeyThatIsANumberIsHandledLikeAnyOther(): void
    {
        // PHP turns such a key into a number wherever it is the key of an array.
        $response = $this->pressed(function (): void {
            text('2024');
            expander('2025', static function (): void {
                text('2026');
            });
            if (button('Save')) {
                field_error('2026', 'Wrong.');
                field_error('2024', 'Wrong.');
            }
        }, 'save');

        self::assertSame(200, $response->status);
        self::assertSame(['f-2024'], $this->texts($response, self::FOCUS));
        self::assertSame('', $this->attr($response, '//details', 'open'));
        self::assertSame(['e-2024', 'e-2026'], $this->texts($response, self::NOTE . '/@id'));
        self::assertSame([2024 => ['Wrong.'], 2026 => ['Wrong.']], $this->logged[0]['field_errors']);
    }

    public function testATickBoxAndAFileFieldCanTakeTheFocus(): void
    {
        $marks = static fn (string $label): \Closure => static function () use ($label): void {
            checkbox('Agree');
            file_input('Report');
            if (button('Save')) {
                field_error($label, 'Wrong.');
            }
        };

        self::assertSame(['f-agree'], $this->texts($this->pressed($marks('Agree'), 'save'), self::FOCUS));
        self::assertSame(['f-report'], $this->texts($this->pressed($marks('Report'), 'save'), self::FOCUS));
    }

    // ------------------------------------------------------------------ parts

    public function testAClosedExpanderOpensForAFieldThatAPressedButtonFoundWrong(): void
    {
        $tool = function (): void {
            expander('Details', static function (): void {
                number('Amount');
                expander('More', static function (): void {
                    text('Reference');
                });
            });
            expander('Other', static function (): void {
                text('Remark');
            });
            if (button('Refund')) {
                field_error('Reference', 'Enter the reference.');
            }
        };

        $response = $this->pressed($tool, 'refund', ['_open' => ['details' => '0', 'more' => '0', 'other' => '0']]);

        // A note that nobody sees helps nobody: every box around the field is open.
        self::assertSame('', $this->attr($response, '//details[summary/@id="x-details"]', 'open'));
        self::assertSame('', $this->attr($response, '//details[summary/@id="x-more"]', 'open'));
        self::assertNull($this->attr($response, '//details[summary/@id="x-other"]', 'open'), 'nothing is wrong in here');
        self::assertSame('1', $this->attr($response, '//input[@name="_open[details]"]', 'value'));
        self::assertSame('1', $this->attr($response, '//input[@name="_open[more]"]', 'value'));
        self::assertSame('0', $this->attr($response, '//input[@name="_open[other]"]', 'value'));
        self::assertSame(['f-reference'], $this->texts($response, self::FOCUS));

        // The next page view starts from what the page shows now.
        $kept = $this->saved()['_open'];
        ksort($kept);
        self::assertSame(['details' => '1', 'more' => '1', 'other' => '0'], $kept);
        self::assertStringNotContainsString('<!--sui', $response->body);
    }

    public function testAnExpanderStaysAsItIsWhenNoButtonWasPressed(): void
    {
        $tool = function (): void {
            expander('Details', static function (): void {
                number('Amount');
            });
            // On every run. A box that sprang open after every click anywhere would be a nuisance.
            field_error('Amount', 'Enter an amount.');
            button('Save');
        };

        $response = $this->post($tool, ['_open' => ['details' => '0']]);

        self::assertNull($this->attr($response, '//details[summary/@id="x-details"]', 'open'));
        self::assertSame('0', $this->attr($response, '//input[@name="_open[details]"]', 'value'));
        self::assertSame(['details' => '0'], $this->saved()['_open']);
        self::assertSame(1, $this->hits($response, '//details' . self::NOTE), 'the note waits inside');

        // The press opens it.
        $pressed = $this->pressed($tool, 'save', ['_open' => ['details' => '0']]);
        self::assertSame('', $this->attr($pressed, '//details[summary/@id="x-details"]', 'open'));
    }

    public function testTheTabWithTheFirstWrongFieldComesIntoView(): void
    {
        $marks = static fn (array $wrong, bool $always = false): \Closure => static function () use ($wrong, $always): void {
            text('Title');
            tabs([
                'Customer' => static function (): void {
                    text('Name');
                },
                'Order' => static function (): void {
                    number('Amount');
                },
                'Notes' => static function (): void {
                    textarea('Remark');
                },
            ]);
            if (button('Save') || $always) {
                foreach ($wrong as $label) {
                    field_error($label, 'Wrong.');
                }
            }
        };
        $inView = fn (Response $response): array => [
            $this->texts($response, '//button[@role="tab" and @aria-selected="true"]'),
            $this->texts($response, '//div[@role="tabpanel" and not(@hidden)]/@id'),
            $this->attr($response, '//input[@name="_tab[tabs]"]', 'value'),
        ];

        // The user looks at "Customer", and the amount is wrong.
        $response = $this->pressed($marks(['Amount']), 'save', ['_tab' => ['tabs' => '0:customer']]);
        self::assertSame([['Order'], ['t-tabs:1'], '1:order'], $inView($response));
        self::assertSame(['tabs' => '1:order'], $this->saved()['_tab']);
        self::assertSame(['f-amount'], $this->texts($response, self::FOCUS));

        // Two tabs hold something wrong. The first of them comes into view, wherever the user was.
        $response = $this->pressed($marks(['Remark', 'Amount']), 'save', ['_tab' => ['tabs' => '2:notes']]);
        self::assertSame([['Order'], ['t-tabs:1'], '1:order'], $inView($response));
        self::assertSame(['f-amount'], $this->texts($response, self::FOCUS));

        // The first wrong field of the page stands above the tabs. It gets the
        // focus, and the tabs still show where their own wrong field is.
        $response = $this->pressed($marks(['Remark', 'Title']), 'save', ['_tab' => ['tabs' => '0:customer']]);
        self::assertSame([['Notes'], ['t-tabs:2'], '2:notes'], $inView($response));
        self::assertSame(['f-title'], $this->texts($response, self::FOCUS));

        // No button was pressed: the tab stays where the user is.
        $response = $this->post($marks(['Amount'], always: true), ['_tab' => ['tabs' => '0:customer']]);
        self::assertSame([['Customer'], ['t-tabs:0'], '0:customer'], $inView($response));
        self::assertSame(['tabs' => '0:customer'], $this->saved()['_tab']);

        // Nothing is wrong in the tabs: the tab stays as well.
        $response = $this->pressed($marks(['Title']), 'save', ['_tab' => ['tabs' => '2:notes']]);
        self::assertSame([['Notes'], ['t-tabs:2'], '2:notes'], $inView($response));
        self::assertStringNotContainsString('<!--sui', $response->body);
    }

    public function testATabThatBrokeStaysInViewWhateverWasFoundWrong(): void
    {
        $response = $this->pressed(function (): void {
            button('Save');
            tabs([
                'First' => static function (): void {
                    text('One');
                    field_error('One', 'Enter one.');
                },
                'Second' => static function (): void {
                    throw new \RuntimeException('the second tab is down');
                },
                'Third' => static function (): void {
                    text('Three');
                },
            ]);
        }, 'save', ['_tab' => ['tabs' => '2:third']]);

        self::assertSame(500, $response->status);
        // The tab that broke is the one to look at. The note waits in its own tab.
        self::assertSame(['Second'], $this->texts($response, '//button[@role="tab" and @aria-selected="true"]'));
        self::assertSame('1:second', $this->attr($response, '//input[@name="_tab[tabs]"]', 'value'));
        self::assertSame(1, $this->hits($response, '//div[@id="t-tabs:0"]' . self::NOTE));
        self::assertStringNotContainsString('<!--sui', $response->body);
    }

    public function testPartsInsidePartsAllComeIntoSight(): void
    {
        $tool = function (): void {
            expander('Advanced', static function (): void {
                tabs([
                    'First' => static function (): void {
                        text('One');
                    },
                    'Second' => static function (): void {
                        expander('Deep', static function (): void {
                            tabs(['A' => static function (): void {
                                note('a');
                            }, 'B' => static function (): void {
                                text('Two');
                            }], key: 'inner');
                        });
                    },
                ]);
            });
            if (button('Save')) {
                field_error('Two', 'Wrong.');
            }
        };

        $response = $this->pressed($tool, 'save');

        self::assertSame('', $this->attr($response, '//details[summary/@id="x-advanced"]', 'open'));
        self::assertSame('', $this->attr($response, '//details[summary/@id="x-deep"]', 'open'));
        self::assertSame(['Second', 'B'], $this->texts($response, '//button[@role="tab" and @aria-selected="true"]'));
        self::assertSame('1:second', $this->attr($response, '//input[@name="_tab[tabs]"]', 'value'));
        self::assertSame('1:b', $this->attr($response, '//input[@name="_tab[inner]"]', 'value'));
        // The field is in a part that is in sight, all the way up.
        self::assertSame(0, $this->hits($response, '//input[@id="f-two"]/ancestor::*[@hidden] | //input[@id="f-two"]/ancestor::details[not(@open)]'));
        self::assertSame(['f-two'], $this->texts($response, self::FOCUS));
    }

    public function testAFieldInAColumnIsMarkedInItsColumn(): void
    {
        $response = $this->get(function (): void {
            columns(
                static function (): void {
                    text('First');
                },
                static function (): void {
                    text('Last');
                },
            );
            field_error('Last', 'Enter the last name.');
        });

        self::assertSame(0, $this->hits($response, '//div[contains(@class,"sui-col")][1]' . self::NOTE));
        self::assertSame(['e-last'], $this->texts($response, '//div[contains(@class,"sui-col")][2]' . self::NOTE . '/@id'));
    }

    public function testAPageThatFailedStillShowsItsFieldsAndWhatWasFoundWrong(): void
    {
        $response = $this->pressed(function (): void {
            expander('Details', static function (): void {
                text('Name');
            });
            if (button('Save')) {
                field_error('Name', 'Enter a name.');

                throw new \RuntimeException('database is down');
            }
        }, 'save', ['_open' => ['details' => '0']]);

        self::assertSame(500, $response->status);
        self::assertSame(['Error: Enter a name.'], $this->texts($response, self::NOTE));
        self::assertSame('', $this->attr($response, '//details', 'open'));
        self::assertStringNotContainsString('<!--sui', $response->body);
    }

    public function testAPageThatStopsHalfWayFillsEveryPlaceItKept(): void
    {
        $response = $this->pressed(function (): void {
            text('Name');
            expander('Details', static function (): void {
                number('Amount');
                field_error('Amount', 'Enter an amount.');
                stop();
            });
            tabs(['Address' => static function (): void {
                text('City');
                stop();
            }]);
            button('Save');
            field_error('Name', 'Enter a name.');
            stop();
        }, 'save', ['_open' => ['details' => '0']]);

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<!--sui', $response->body);
        self::assertSame(['Error: Enter a name.', 'Error: Enter an amount.'], $this->texts($response, self::NOTE));
        self::assertSame(['f-name'], $this->texts($response, self::FOCUS));
        self::assertSame('', $this->attr($response, '//details', 'open'));
        self::assertSame(1, $this->hits($response, '//div[@role="tabpanel"]//input[@id="f-city"]'));
    }

    // ---------------------------------------------------------------- the log

    public function testTheLogSaysWhatWasFoundWrong(): void
    {
        $tool = function (): void {
            $amount = number('Amount', key: 'amt');
            $email = text('Email');
            if (button('Refund')) {
                if (!str_contains($email, '@')) {
                    field_error('Email', 'Enter an email address.');
                }
                if ($amount === null || $amount <= 0) {
                    field_error('Amount', 'Enter an amount.');
                    field_error('Amount', 'It has to be above 0.');
                }
            }
        };

        // A pressed button is in the log. Whoever reads it has to see that the work was not done.
        $this->pressed($tool, 'refund', ['amt' => '-5', 'email' => 'ava']);
        self::assertSame('Refund', $this->logged[0]['action']);
        self::assertSame(
            ['amt' => ['Enter an amount.', 'It has to be above 0.'], 'email' => ['Enter an email address.']],
            $this->logged[0]['field_errors'],
            'by the key of the field, in the order of the page',
        );

        $this->pressed($tool, 'refund', ['amt' => '5', 'email' => 'ava@example.com']);
        self::assertSame([], $this->logged[1]['field_errors']);
    }

    // -------------------------------------------------------------- languages

    public function testTheWordInFrontOfTheMessageIsInTheLanguageOfThePage(): void
    {
        $arabic = $this->get(function (): void {
            title('الطلبات');
            text('الاسم');
            field_error('الاسم', 'اكتب الاسم.');
        });
        self::assertSame(['خطأ:'], $this->texts($arabic, self::NOTE . '/span[@class="sui-sr"]'));
        self::assertSame(['خطأ: اكتب الاسم.'], $this->texts($arabic, self::NOTE));
        self::assertSame('e-الاسم', $this->attr($arabic, '//input[@name="الاسم"]', 'aria-describedby'));

        $this->config = new Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        $german = $this->get(function (): void {
            text('Name');
            field_error('Name', 'Bitte einen Namen eingeben.');
        });
        self::assertSame(['Fehler: Bitte einen Namen eingeben.'], $this->texts($german, self::NOTE));
    }
}
