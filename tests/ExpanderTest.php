<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Request;
use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\checkbox;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\download;
use function Runio\ScriptUi\expander;
use function Runio\ScriptUi\info;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\stop;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\textarea;

/**
 * expander() puts a part of the page under a line that opens and closes it.
 * The part is always there: closed only means out of sight.
 */
final class ExpanderTest extends ToolTestCase
{
    private const BOX = '//details[contains(@class,"sui-expander")]';

    /** Is the expander with this key drawn open? */
    private function isOpen(Response $response, string $key): bool
    {
        return $this->hits($response, self::BOX . '[summary[@id="x-' . $key . '"] and @open]') === 1;
    }

    public function testAnExpanderHidesItsContentUnderALine(): void
    {
        $response = $this->get(function (): void {
            note('before');
            expander('Advanced options', static function (): void {
                note('inside');
            });
            note('after');
        });

        self::assertSame(200, $response->status);
        self::assertSame(['Advanced options'], $this->texts($response, self::BOX . '/summary[@id="x-advanced-options"]'));
        self::assertSame(['inside'], $this->texts($response, self::BOX . '/div[contains(@class,"sui-expander-body")]/p'));
        self::assertSame(['before', 'inside', 'after'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
        self::assertFalse($this->isOpen($response, 'advanced-options'), 'closed until somebody opens it');
    }

    public function testItCanStartOpen(): void
    {
        $response = $this->get(function (): void {
            expander('Details', static fn () => note('inside'), open: true);
        });

        self::assertTrue($this->isOpen($response, 'details'));
        self::assertSame('1', $this->attr($response, '//input[@name="_open[details]"]', 'value'));
    }

    public function testInputsInsideWorkWhileItIsClosed(): void
    {
        // The fields are in the form whether the box is open or not.
        $limit = $urgent = null;
        $tool = function () use (&$limit, &$urgent): void {
            expander('Advanced options', function () use (&$limit, &$urgent): void {
                $limit = text('Limit', default: '10');
                $urgent = checkbox('Urgent');
            });
        };

        $first = $this->get($tool);
        self::assertSame('10', $limit);
        self::assertFalse($this->isOpen($first, 'advanced-options'));

        $this->post($tool, ['limit' => '25', 'urgent' => '1'] + $this->form($first));
        self::assertSame('25', $limit);
        self::assertTrue($urgent);

        $this->get($tool);
        self::assertSame('25', $limit, 'and what they hold is remembered');
    }

    public function testOpenOrClosedTravelsInTheForm(): void
    {
        $tool = function (): void {
            expander('Advanced options', static fn () => note('inside'));
        };

        $closed = $this->get($tool);
        self::assertSame('0', $this->attr($closed, '//input[@type="hidden" and @name="_open[advanced-options]"]', 'value'));

        // The page script writes "1" into the field when the user opens the box.
        $opened = $this->post($tool, ['_open' => ['advanced-options' => '1']] + $this->form($closed));
        self::assertTrue($this->isOpen($opened, 'advanced-options'));
        self::assertSame('1', $this->attr($opened, '//input[@name="_open[advanced-options]"]', 'value'));

        self::assertTrue($this->isOpen($this->get($tool), 'advanced-options'), 'it is still open when the page is opened again');

        $shut = $this->post($tool, ['_open' => ['advanced-options' => '0']] + $this->form($opened));
        self::assertFalse($this->isOpen($shut, 'advanced-options'));
        self::assertFalse($this->isOpen($this->get($tool), 'advanced-options'));
    }

    public function testAnExpanderThatStartsOpenCanBeClosedForGood(): void
    {
        $tool = function (): void {
            expander('Details', static fn () => note('inside'), open: true);
        };

        $this->post($tool, ['_open' => ['details' => '0']]);

        self::assertFalse($this->isOpen($this->get($tool), 'details'));
    }

    public function testABoxThatLeavesThePageStartsTheWayTheScriptSaysWhenItComesBack(): void
    {
        // Like an input: what a run does not draw, it does not keep.
        $drawn = true;
        $tool = function () use (&$drawn): void {
            note('always there');
            if ($drawn) {
                expander('Details', static fn () => note('inside'));
            }
        };

        $first = $this->post($tool, ['_open' => ['details' => '1']]);
        self::assertTrue($this->isOpen($first, 'details'));

        $drawn = false;
        $second = $this->post($tool, $this->form($first));
        self::assertSame(0, $this->hits($second, '//details'));

        $drawn = true;
        self::assertFalse($this->isOpen($this->post($tool, $this->form($second)), 'details'));
        self::assertFalse($this->isOpen($this->get($tool), 'details'));
    }

    public function testOnlyOpenAndClosedCountAsState(): void
    {
        $tool = function (): void {
            expander('Details', static fn () => note('inside'), open: true);
            expander('More', static fn () => note('more'));
        };

        $response = $this->post($tool, ['_open' => ['details' => 'no', 'more' => ['1']]]);
        self::assertTrue($this->isOpen($response, 'details'), 'anything else is the way it starts');
        self::assertFalse($this->isOpen($response, 'more'));

        $response = $this->post($tool, ['_open' => 'details']);
        self::assertTrue($this->isOpen($response, 'details'));
    }

    public function testRerunKeepsItOpen(): void
    {
        // The box is below the call, so the run that reruns never reaches it.
        $tool = function (): void {
            if (button('Save')) {
                rerun();
            }
            expander('Details', static fn () => note('inside'));
        };

        $this->post($tool, ['_click' => 'save', '_open' => ['details' => '1', 'not a key!' => '1', 'other' => 'x']]);

        self::assertTrue($this->isOpen($this->get($tool), 'details'));
        $states = $this->session->get('state');
        self::assertSame(['details' => '1'], array_values($states)[0]['_open'], 'only what an expander sends is kept');
    }

    // ------------------------------------------- what the page script reports

    public function testThePageScriptReportsHowABoxWasLeftWithoutRunningTheTool(): void
    {
        // Opening a box needs no answer from the server. But the server has to hear of
        // it, or the box would be closed again when the page is loaded the next time.
        $runs = 0;
        $name = null;
        $tool = function () use (&$runs, &$name): void {
            $runs++;
            $name = text('Name');
            expander('Details', static fn () => note('inside'));
        };

        $this->post($tool, ['name' => 'Ava']);
        $runs = 0;

        $response = $this->post($tool, ['_open' => ['details' => '1'], 'name' => 'Eve'], ['X-Script-Ui' => 'layout']);
        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(0, $runs, 'the tool did not run');

        self::assertTrue($this->isOpen($this->get($tool), 'details'), 'the next page view shows the box the way it was left');
        self::assertSame('Ava', $name, 'and nothing else was taken from the report');

        $this->post($tool, ['_open' => ['details' => '0']], ['X-Script-Ui' => 'layout']);
        self::assertFalse($this->isOpen($this->get($tool), 'details'));
        self::assertSame('Ava', $name);
    }

    public function testAReportNeedsTheTokenOfTheForm(): void
    {
        $tool = function (): void {
            expander('Details', static fn () => note('inside'));
        };
        $this->token();

        $response = $this->kernel()->handle(
            new Request('POST', '/tool', [], ['_open' => ['details' => '1']], ['X-Script-Ui' => 'layout']),
            'tool',
            $tool,
        );
        self::assertSame(419, $response->status);
        self::assertFalse($this->isOpen($this->get($tool), 'details'));

        // And it is a form that is sent, never a link that is followed.
        $response = $this->get($tool, ['X-Script-Ui' => 'layout'], ['_open' => ['details' => '1'], '_token' => $this->token()]);
        self::assertSame(200, $response->status);
        self::assertFalse($this->isOpen($response, 'details'));
    }

    public function testAReportOnAPageThatWasNeverSubmittedIsKeptToo(): void
    {
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name', default: 'nobody');
            expander('Details', static fn () => note('inside'));
        };

        $this->get($tool);
        $this->post($tool, ['_open' => ['details' => '1', 'not a key!' => '1', 'other' => 'yes']], ['X-Script-Ui' => 'layout']);

        self::assertTrue($this->isOpen($this->get($tool), 'details'));
        self::assertSame('nobody', $name);
        // The mark says that no run of the tool saved this.
        self::assertSame(['_open' => ['details' => '1'], '_no_run' => '1'], array_values($this->session->get('state'))[0]);

        // The first run that does save takes the mark away, and keeps what was reported.
        $this->post($tool, ['name' => 'Ava'] + $this->form($this->get($tool)));
        $state = array_values($this->session->get('state'))[0];
        self::assertArrayNotHasKey('_no_run', $state);
        self::assertSame(['details' => '1'], $state['_open']);

        // A report that says nothing leaves nothing behind.
        $this->session->set('state', []);
        $this->post($tool, ['_open' => ['not a key!' => '1']], ['X-Script-Ui' => 'layout']);
        self::assertSame([], $this->session->get('state'));
    }

    public function testManyReportsKeepNoMoreThanOneFormCouldHold(): void
    {
        $tool = function (): void {
            expander('Details', static fn () => note('inside'));
        };

        for ($batch = 0; $batch < 3; $batch++) {
            $made = [];
            for ($n = 0; $n < 400; $n++) {
                $made['made-up-' . $batch . '-' . $n] = '1';
            }
            $this->post($tool, ['_open' => $made], ['X-Script-Ui' => 'layout']);
        }
        $this->post($tool, ['_open' => ['details' => '1']], ['X-Script-Ui' => 'layout']);

        $kept = array_values($this->session->get('state'))[0]['_open'];
        self::assertCount(500, $kept);
        self::assertSame('details', array_key_first($kept), 'what the last report named is kept first');
        self::assertTrue($this->isOpen($this->get($tool), 'details'));
    }

    public function testAReportThatDoesNotFitLeavesTheSavedInputsAlone(): void
    {
        // The saved inputs of this tool are close to all that a session keeps for a tool.
        $tool = function (): void {
            for ($n = 1; $n <= 31; $n++) {
                textarea('Text ' . $n);
            }
            expander('Details', static fn () => note('inside'));
        };
        $long = [];
        for ($n = 1; $n <= 31; $n++) {
            $long['text-' . $n] = str_repeat('x', 8192);
        }
        $this->post($tool, $long);
        $before = $this->session->get('state');
        self::assertSame(8192, strlen(array_values($before)[0]['text-31'] ?? ''), 'the inputs were saved');

        // A report that names as many boxes as a form may hold. Kept, the whole would be too much.
        $made = [];
        for ($n = 0; $n < 500; $n++) {
            $made[str_repeat('k', 60) . $n] = '1';
        }
        $response = $this->post($tool, ['_open' => $made], ['X-Script-Ui' => 'layout']);

        self::assertSame(204, $response->status);
        self::assertSame($before, $this->session->get('state'), 'the report is dropped, and not the inputs');
    }

    public function testAReportIsNoRunToCompareTheNextFormWith(): void
    {
        // A table goes back to its first page when an input changed and its rows are
        // others than before. After a report the first form must not look like a change.
        $count = 60;
        $tool = function () use (&$count): void {
            text('Note', default: 'x');
            expander('Details', static fn () => note('inside'));
            table(array_map(static fn (int $id): array => ['id' => $id], range(1, $count)));
        };

        $first = $this->get($tool);
        $this->post($tool, ['_open' => ['details' => '1']], ['X-Script-Ui' => 'layout']);

        $count = 61;
        $second = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($first));
        self::assertSame(['Page 2 of 3'], $this->texts($second, '//*[contains(@class,"sui-page-now")]'));
    }

    public function testAReportIsFiledUnderTheAddressItCameFrom(): void
    {
        // Two tools can share a script and differ in the query string. See Kernel::savedAt().
        $tool = function (): void {
            expander('Details', static fn () => note('inside'));
        };
        $kernel = $this->kernel();
        $at = fn (string $method, string $page, array $post = [], array $headers = []): Response => $kernel->handle(
            new Request($method, '/tool.php', ['page' => $page], $post, $headers),
            'tool',
            $tool,
            selfUrl: '/tool.php?page=' . $page,
        );

        $at('POST', 'refund', ['_token' => $this->token(), '_open' => ['details' => '1']], ['X-Script-Ui' => 'layout']);

        self::assertTrue($this->isOpen($at('GET', 'refund'), 'details'));
        self::assertFalse($this->isOpen($at('GET', 'customers'), 'details'));
    }

    public function testTheNameOfAFunctionIsNotTakenForAFunction(): void
    {
        $response = $this->get(function (): void {
            expander('Server', 'phpinfo');
        });

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('PHP Version', $response->body, 'phpinfo() did not run');
        self::assertStringContainsString('A name in quotes is not run.', html_entity_decode($response->body));
        self::assertSame(0, $this->hits($response, '//details[contains(@class,"sui-expander")]'), 'no box is drawn');
    }

    public function testALinkCannotOpenIt(): void
    {
        // Like every field of the library itself: a link only fills in the fields of the tool.
        $response = $this->get(function (): void {
            expander('Details', static fn () => note('inside'));
        }, query: ['_open' => ['details' => '1']]);

        self::assertFalse($this->isOpen($response, 'details'));
    }

    public function testAnExpanderHasAKeyLikeAnInput(): void
    {
        $response = $this->get(function (): void {
            expander('Details', static fn () => note('one'));
            expander('Details', static fn () => note('two'));
        });
        self::assertSame(500, $response->status);
        self::assertStringContainsString('key:', $response->body);

        $response = $this->get(function (): void {
            text('Details');
            expander('Details', static fn () => note('two'));
        });
        self::assertSame(500, $response->status);

        $response = $this->get(function (): void {
            expander('Details', static fn () => note('one'), key: 'first');
            expander('Details', static fn () => note('two'), key: 'second');
        });
        self::assertSame(200, $response->status);
        self::assertSame(['Details', 'Details'], $this->texts($response, self::BOX . '/summary'));
        self::assertSame(1, $this->hits($response, '//summary[@id="x-first"]'));
        self::assertSame(1, $this->hits($response, '//input[@name="_open[second]"]'));
    }

    public function testWhatWasDrawnStaysVisibleWhenTheContentFails(): void
    {
        $response = $this->get(function (): void {
            expander('Details', static function (): void {
                note('drawn');
                throw new \RuntimeException('Broken inside.');
            }, open: true);
            note('never reached');
        });

        self::assertSame(500, $response->status);
        self::assertSame(['drawn'], $this->texts($response, self::BOX . '/div/p[contains(@class,"sui-note")]'));
        self::assertSame(0, $this->hits($response, '//p[text()="never reached"]'));
        self::assertStringContainsString('Broken inside.', $response->body);
    }

    public function testStopInsideEndsThePartAndNotThePage(): void
    {
        // A part that can be out of sight must not end the page: nobody would see why it ends.
        $response = $this->get(function (): void {
            expander('Details', static function (): void {
                note('drawn');
                stop();
            });
            note('reached');
        });

        self::assertSame(200, $response->status);
        self::assertSame(['drawn'], $this->texts($response, self::BOX . '/div/p'));
        self::assertSame(['drawn', 'reached'], $this->texts($response, '//p[contains(@class,"sui-note")]'));
    }

    public function testRerunAndADownloadInsideStillEndTheRun(): void
    {
        $saves = $below = 0;
        $tool = function () use (&$saves, &$below): void {
            expander('Details', static function () use (&$saves): void {
                if (button('Save')) {
                    $saves++;
                    rerun();
                }
                download('Export', 'a,b', 'export.csv');
            });
            $below++;
        };

        $response = $this->post($tool, ['_click' => 'save']);
        self::assertSame(303, $response->status);
        self::assertSame(1, $saves);
        self::assertSame(0, $below, 'rerun() ended the run inside the box');

        $response = $this->post($tool, ['_click' => 'export']);
        self::assertSame(200, $response->status);
        self::assertSame('a,b', $response->body);
        self::assertSame(0, $below, 'the file is the answer: nothing below it runs');
    }

    public function testAPartThatJustAnsweredIsDrawnOpen(): void
    {
        // Without the page script the server does not hear that a box was opened. A
        // click on something inside it proves that it was, and its answer must be seen.
        $tool = function (): void {
            expander('Danger zone', static function (): void {
                if (button('Delete', confirm: 'Really delete?')) {
                    success('Deleted.');
                }
                table([['id' => 1], ['id' => 2], ['id' => 3]], pageSize: 2);
            });
        };

        $closed = $this->get($tool);
        self::assertFalse($this->isOpen($closed, 'danger-zone'));

        $asked = $this->post($tool, ['_click' => 'delete'] + $this->form($closed));
        self::assertTrue($this->isOpen($asked, 'danger-zone'), 'the question is inside');
        self::assertSame('1', $this->attr($asked, '//input[@name="_open[danger-zone]"]', 'value'));

        $this->session->set('state', []);
        $yes = (string) $this->attr($asked, '//button[@id="b-delete:yes"]', 'value');
        $done = $this->post($tool, ['_click' => $yes] + $this->form($closed));
        self::assertTrue($this->isOpen($done, 'danger-zone'), 'and so is the result');
        self::assertSame(['Deleted.'], $this->texts($done, self::BOX . '//*[contains(@class,"sui-success")]'));

        $this->session->set('state', []);
        $paged = $this->post($tool, ['_click' => 'table:page:2'] + $this->form($closed));
        self::assertTrue($this->isOpen($paged, 'danger-zone'), 'a click in a table inside counts too');

        $this->session->set('state', []);
        $other = $this->post($tool, ['_click' => 'something-else'] + $this->form($closed));
        self::assertFalse($this->isOpen($other, 'danger-zone'));
    }

    public function testAButtonOutsideOpensNoBoxWhateverTheBoxSays(): void
    {
        // A box with a tip or a warning in it would spring open after every click on
        // the page. What a button has to say belongs where the button is.
        $tool = function (): void {
            $saved = button('Save');
            expander('Help', static function () use ($saved): void {
                info('A tip that is shown on every run.');
                if ($saved) {
                    success('Saved.');
                }
            });
        };

        self::assertFalse($this->isOpen($this->get($tool), 'help'));
        self::assertFalse($this->isOpen($this->post($tool, ['_click' => 'save']), 'help'));
    }

    public function testABoxThatAClickInsideOpenedIsStillOpenAfterRerunAndAfterADownload(): void
    {
        // Without the page script the form says "closed" although the user opened the
        // box. The click inside is what tells the server, and that has to outlast a
        // run that ends in rerun() or in a file.
        $tool = function (): void {
            expander('Edit', static function (): void {
                if (button('Save')) {
                    success('Saved.');
                    rerun();
                }
            });
            expander('Export', static function (): void {
                download('Get the file', 'a,b', 'export.csv');
            });
            expander('Untouched', static fn () => note('inside'));
        };

        $closed = $this->get($tool);
        self::assertSame('0', $this->attr($closed, '//input[@name="_open[edit]"]', 'value'));

        $response = $this->post($tool, ['_click' => 'save'] + $this->form($closed));
        self::assertSame(303, $response->status);
        $after = $this->get($tool);
        self::assertTrue($this->isOpen($after, 'edit'));
        self::assertFalse($this->isOpen($after, 'export'));

        // The run ends where the file is made. The box below is not reached.
        $form = $this->form($closed);
        $form['_open']['untouched'] = '1';
        $file = $this->post($tool, ['_click' => 'get-the-file'] + $form);
        self::assertSame('a,b', $file->body);
        $after = $this->get($tool);
        self::assertTrue($this->isOpen($after, 'export'));
        self::assertTrue($this->isOpen($after, 'untouched'), 'a box that the run did not reach stays as the form said');
        self::assertFalse($this->isOpen($after, 'edit'), 'and one that it reached is as the run drew it');
    }

    public function testAPartThatFailedIsDrawnOpen(): void
    {
        $response = $this->get(function (): void {
            expander('Details', static function (): void {
                note('drawn');
                throw new \RuntimeException('Broken inside.');
            });
        });

        self::assertSame(500, $response->status);
        self::assertTrue($this->isOpen($response, 'details'));
    }

    public function testExpandersAndColumnsCanBeNested(): void
    {
        $response = $this->get(function (): void {
            expander('Outer', static function (): void {
                columns(
                    static fn () => note('left'),
                    static function (): void {
                        expander('Inner', static fn () => note('deep'), open: true);
                    },
                );
            });
        });

        self::assertSame(200, $response->status);
        $inner = self::BOX . '[summary[@id="x-outer"]]//div[contains(@class,"sui-col")]' . self::BOX . '[summary[@id="x-inner"]]';
        self::assertSame(['deep'], $this->texts($response, $inner . '/div/p'));
        self::assertTrue($this->isOpen($response, 'inner'));
        self::assertFalse($this->isOpen($response, 'outer'));
    }

    public function testPrintedOutputLandsWhereItWasPrinted(): void
    {
        $response = $this->get(function (): void {
            echo 'printed before';
            expander('Details', static function (): void {
                echo 'printed inside';
            });
            echo 'printed after';
        });

        self::assertSame(['printed inside'], $this->texts($response, self::BOX . '/div/pre[contains(@class,"sui-echo")]'));
        self::assertSame(['printed before', 'printed inside', 'printed after'], $this->texts($response, '//pre[contains(@class,"sui-echo")]'));
    }

    public function testTheLabelIsTextAndNeverMarkup(): void
    {
        $response = $this->get(function (): void {
            expander('<img src=x onerror=alert(1)>', static fn () => note('inside'), key: 'odd');
        });

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString('<img src=x', $response->body);
        self::assertSame(['<img src=x onerror=alert(1)>'], $this->texts($response, self::BOX . '/summary'));
    }

    public function testNoIdIsUsedTwice(): void
    {
        $response = $this->get(function (): void {
            expander('Details', static function (): void {
                text('Name');
                button('Go');
            });
        });

        $ids = $this->texts($response, '//*[@id]/@id');
        self::assertContains('x-details', $ids);
        self::assertSame(array_values(array_unique($ids)), $ids);
    }
}
