<?php

declare(strict_types=1);

namespace Runio\ScriptUi\Tests;

use Runio\ScriptUi\Http\Response;
use Runio\ScriptUi\Tests\Support\ToolTestCase;

use function Runio\ScriptUi\button;
use function Runio\ScriptUi\columns;
use function Runio\ScriptUi\download;
use function Runio\ScriptUi\expander;
use function Runio\ScriptUi\file_input;
use function Runio\ScriptUi\info;
use function Runio\ScriptUi\note;
use function Runio\ScriptUi\refresh;
use function Runio\ScriptUi\rerun;
use function Runio\ScriptUi\stop;
use function Runio\ScriptUi\success;
use function Runio\ScriptUi\table;
use function Runio\ScriptUi\tabs;
use function Runio\ScriptUi\text;
use function Runio\ScriptUi\title;

/**
 * refresh() lets a page run again by itself, so that a dashboard stays up to
 * date. The page script does the waiting. The server draws the line that says
 * so, and treats a run that nobody asked for with care: it presses no button,
 * and it changes nothing that the tool remembers.
 */
final class RefreshTest extends ToolTestCase
{
    private const LINE = '//p[contains(@class,"sui-refresh")]';

    /** A run that the page script started by itself. */
    private function refreshed(callable $tool, array $fields = []): Response
    {
        return $this->post($tool, $fields, ['X-Script-Ui' => 'refresh']);
    }

    public function testThePageSaysHowOftenItRunsAgainAndWhenItWasMade(): void
    {
        $before = time();
        $response = $this->get(function (): void {
            note('above');
            refresh(30);
            note('below');
        });

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->hits($response, self::LINE));
        self::assertSame('30', $this->attr($response, self::LINE, 'data-sui-refresh'));
        self::assertSame('3600', $this->attr($response, self::LINE, 'data-sui-stop-after'), 'an hour without a touch, unless the script says otherwise');

        // The time is written for people and for the page script, which shows it in the zone of the browser.
        $made = (string) $this->attr($response, self::LINE . '//time', 'datetime');
        $at = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $made);
        self::assertInstanceOf(\DateTimeImmutable::class, $at, $made);
        self::assertGreaterThanOrEqual($before, $at->getTimestamp());
        self::assertLessThanOrEqual(time(), $at->getTimestamp());
        self::assertSame($at->format('H:i:s'), $this->texts($response, self::LINE . '//time')[0]);
        self::assertSame('Updated ' . $at->format('H:i:s'), $this->texts($response, self::LINE . '/span[@class="sui-refresh-time"]')[0]);

        // It stands where it was called.
        self::assertSame(['above'], $this->texts($response, self::LINE . '/preceding-sibling::p'));
        self::assertSame(['below'], $this->texts($response, self::LINE . '/following-sibling::p'));
    }

    public function testTheButtonThatPausesItBelongsToThePageScript(): void
    {
        $response = $this->get(function (): void {
            refresh(30);
        });

        // Without the script nothing runs by itself, so there is nothing to pause.
        $button = self::LINE . '/button';
        self::assertSame('button', $this->attr($response, $button, 'type'));
        self::assertSame('', $this->attr($response, $button, 'hidden'));
        self::assertNull($this->attr($response, $button, 'name'), 'it sends nothing');
        self::assertSame('Pause', $this->attr($response, $button, 'data-sui-pause'));
        self::assertSame('Resume', $this->attr($response, $button, 'data-sui-resume'));
        self::assertSame(['Pause'], $this->texts($response, $button));

        // The word that says it stands still is read out when it appears. The time never is.
        $paused = self::LINE . '/span[@role="status"]';
        self::assertSame('Paused', $this->attr($response, $paused, 'data-sui-text'));
        self::assertSame([''], $this->texts($response, $paused));
        self::assertSame(0, $this->hits($response, self::LINE . '//*[@aria-live] | ' . self::LINE . '/span[@class="sui-refresh-time" and @role]'));

        self::assertStringNotContainsString('http-equiv="refresh"', strtolower($response->body), 'a reload would throw away what the user typed');
        // The page script finds the button again by its name when the page was drawn anew, and gives it the focus back.
        self::assertSame('sui-pause', $this->attr($response, self::LINE . '/button', 'id'));
    }

    public function testTheRateHasLimits(): void
    {
        foreach ([2, 30, 86400] as $seconds) {
            $response = $this->get(function () use ($seconds): void {
                refresh($seconds, stopAfter: null);
            });
            self::assertSame(200, $response->status, $seconds . ' seconds');
            self::assertSame((string) $seconds, $this->attr($response, self::LINE, 'data-sui-refresh'));
        }

        foreach ([1, 0, -5, 86401] as $seconds) {
            $response = $this->get(function () use ($seconds): void {
                refresh($seconds, stopAfter: null);
            });
            self::assertSame(500, $response->status, $seconds . ' seconds');
            self::assertStringContainsString('refresh() takes from 2 seconds to a day (86400)', html_entity_decode($response->body));
            self::assertSame(0, $this->hits($response, self::LINE));
        }
    }

    public function testItCanGoOnForEverAndCannotStopBeforeItStarts(): void
    {
        $response = $this->get(function (): void {
            refresh(30, stopAfter: null);
        });
        self::assertSame(200, $response->status);
        self::assertNull($this->attr($response, self::LINE, 'data-sui-stop-after'), 'a screen on a wall');

        $response = $this->get(function (): void {
            refresh(30, stopAfter: 600);
        });
        self::assertSame('600', $this->attr($response, self::LINE, 'data-sui-stop-after'));

        $response = $this->get(function (): void {
            refresh(30, stopAfter: 31);
        });
        self::assertSame(200, $response->status);

        // The page stops when nobody touched it for that long: with these it would
        // stop at the moment it is due to run again for the first time.
        foreach ([[30, 30], [30, 29], [3600, 3600]] as [$seconds, $stopAfter]) {
            $response = $this->get(function () use ($seconds, $stopAfter): void {
                refresh($seconds, stopAfter: $stopAfter);
            });
            self::assertSame(500, $response->status);
            self::assertStringContainsString('stopAfter: has to be longer than the wait', html_entity_decode($response->body));
        }

        // An hour is the wait at which the rule of an hour stops fitting, and the page says so.
        $response = $this->get(function (): void {
            refresh(3600);
        });
        self::assertSame(500, $response->status);
        self::assertStringContainsString('refresh(3600, stopAfter: 3600)', html_entity_decode($response->body));
    }

    public function testAPageHasOneRate(): void
    {
        $response = $this->get(function (): void {
            refresh(30);
            expander('More', static function (): void {
                refresh(10);
            });
        });

        self::assertSame(500, $response->status);
        self::assertStringContainsString('refresh() is called twice on this page', html_entity_decode($response->body));
        self::assertSame(1, $this->hits($response, self::LINE));
    }

    public function testItsLineIsNeverOutOfSight(): void
    {
        // The line says "Paused" when the page stands still, and holds the button that
        // starts it again. Behind a closed tab nobody would see either.
        $hidden = [
            'expander()' => fn () => expander('More', fn () => refresh(30)),
            'tabs()' => fn () => tabs(['One' => fn () => note('one'), 'Two' => fn () => refresh(30)]),
            'columns() in an expander' => fn () => expander('More', fn () => columns(fn () => refresh(30), fn () => note('next to it'))),
        ];
        foreach ($hidden as $where => $tool) {
            $response = $this->get($tool);
            self::assertSame(500, $response->status, $where);
            self::assertStringContainsString('refresh() is called inside expander() or tabs()', html_entity_decode($response->body), $where);
            self::assertSame(0, $this->hits($response, self::LINE), $where);
        }

        // Next to something else it is in sight. And after a part that can be closed, too.
        $response = $this->get(function (): void {
            expander('More', fn () => note('inside'));
            tabs(['One' => fn () => note('one')]);
            columns(fn () => refresh(30), fn () => note('next to it'));
        });
        self::assertSame(200, $response->status);
        self::assertSame(1, $this->hits($response, self::LINE));
    }

    public function testAPageThatEndsBeforeTheCallDoesNotAskToBeRunAgain(): void
    {
        $response = $this->get(function (): void {
            note('Nothing to show.');
            stop();
            refresh(30);
        });

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->hits($response, self::LINE));
    }

    public function testARunThatNobodyAskedForIsAnsweredWithTheContentOnly(): void
    {
        $name = null;
        $tool = function () use (&$name): void {
            title('Queue');
            $name = text('Name');
            refresh(30);
        };

        $response = $this->refreshed($tool, ['name' => 'Ava']);

        self::assertSame(200, $response->status);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertStringNotContainsString('<html', $response->body);
        self::assertSame('Ava', $name, 'it reads the form as every run does');
        self::assertSame('30', $this->attr($response, self::LINE, 'data-sui-refresh'));
    }

    public function testARefreshThatEndsInRerunIsAnsweredWithTheContentOnlyToo(): void
    {
        // rerun() sends the browser to the page again, and the page script follows with
        // the same words in its request. A whole page as the answer would make it load
        // everything anew, every few seconds.
        $tool = function (): void {
            title('Queue');
            refresh(30);
            rerun();
        };
        self::assertSame(303, $this->refreshed($tool)->status);

        $response = $this->get(function (): void {
            title('Queue');
            refresh(30);
        }, ['X-Script-Ui' => 'refresh']);

        self::assertSame(200, $response->status);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertStringNotContainsString('<html', $response->body);
        self::assertSame('30', $this->attr($response, self::LINE, 'data-sui-refresh'));
    }

    public function testItNeedsTheTokenOfTheFormLikeEveryRun(): void
    {
        $runs = 0;
        $tool = function () use (&$runs): void {
            $runs++;
            refresh(30);
        };
        $this->token();
        $runs = 0;

        $response = $this->kernel()->handle(
            new \Runio\ScriptUi\Http\Request('POST', '/tool', [], ['_token' => 'made up'], ['X-Script-Ui' => 'refresh']),
            'tool',
            $tool,
        );

        self::assertSame(419, $response->status);
        self::assertSame(0, $runs);
    }

    public function testItChangesNothingThatTheToolRemembers(): void
    {
        // Two windows show the same tool with other inputs. Each of them refreshes. If a
        // refresh saved its form, the two would overwrite each other all day long.
        $name = null;
        $tool = function () use (&$name): void {
            $name = text('Name');
            refresh(30);
        };

        $this->post($tool, ['name' => 'saved by the user']);
        $before = $this->session->get('state');

        $this->refreshed($tool, ['name' => 'the other window']);
        self::assertSame('the other window', $name);
        self::assertSame($before, $this->session->get('state'));

        $this->get($tool);
        self::assertSame('saved by the user', $name);
    }

    public function testItSavesNothingOnAPageThatWasNeverSubmittedEither(): void
    {
        $tool = function (): void {
            text('Name');
            refresh(30);
        };

        $this->refreshed($tool, ['name' => 'Ava']);

        self::assertSame([], $this->session->get('state') ?? []);
    }

    public function testItNeverPressesAButton(): void
    {
        $pressed = 0;
        $tool = function () use (&$pressed): void {
            refresh(30);
            if (button('Delete everything')) {
                $pressed++;
                success('Deleted.');
            }
            if (button('Delete with a question', confirm: 'Sure?')) {
                $pressed++;
            }
        };

        $first = $this->get($tool);
        $response = $this->refreshed($tool, ['_click' => 'delete-everything'] + $this->form($first));

        self::assertSame(200, $response->status);
        self::assertSame(0, $pressed);
        self::assertSame([], $this->logged);
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-success")]'));

        // Nor does it ask a question, or answer one.
        $response = $this->refreshed($tool, ['_click' => 'delete-with-a-question'] + $this->form($first));
        self::assertSame(0, $this->hits($response, '//*[contains(@class,"sui-confirm")]'));
        self::assertSame(0, $pressed);
    }

    public function testItPressesNothingInATableOrAFileFieldEither(): void
    {
        $tool = function (): void {
            refresh(30);
            table([['id' => 1, 'name' => 'Zed'], ['id' => 2, 'name' => 'Ava']], key: 'people');
            file_input('Report');
        };
        $names = '//table//tbody/tr/td[2]';

        $first = $this->post($tool, [], files: ['report' => $this->arrived('report.csv', "a,b\n1,2\n")]);
        self::assertSame(['Zed', 'Ava'], $this->texts($first, $names));
        self::assertCount(1, $this->stored());
        $sort = ['_click' => (string) $this->attr($first, '(//button[contains(@class,"sui-sort")])[2]', 'value')] + $this->form($first);
        $remove = ['_click' => (string) $this->attr($first, '//button[contains(@id, ":remove:")]', 'value')] + $this->form($first);

        // The header of a column and the "remove" of a file are buttons too.
        self::assertSame(['Zed', 'Ava'], $this->texts($this->refreshed($tool, $sort), $names));
        $this->refreshed($tool, $remove);
        self::assertCount(1, $this->stored());

        // The same two requests from the user do what they say.
        self::assertSame(['Ava', 'Zed'], $this->texts($this->post($tool, $sort), $names));
        $this->post($tool, $remove);
        self::assertCount(0, $this->stored());
    }

    public function testItHandsOutNoFile(): void
    {
        $made = 0;
        $tool = function () use (&$made): void {
            refresh(30);
            download('Export', function () use (&$made): string {
                $made++;

                return "a,b\n1,2\n";
            }, 'export.csv');
        };

        $first = $this->get($tool);
        $response = $this->refreshed($tool, ['_click' => 'export'] + $this->form($first));

        self::assertSame(200, $response->status);
        self::assertSame('1', $response->header('X-Script-Ui-Fragment'));
        self::assertNull($response->header('X-Script-Ui-Download'));
        self::assertSame(0, $made);
        self::assertSame([], $this->logged);
    }

    public function testItDeletesNoFile(): void
    {
        // A run that went well deletes the files of a field that is not drawn any more.
        // A refresh is not the user's doing, so it deletes nothing.
        $drawn = true;
        $tool = function () use (&$drawn): void {
            refresh(30);
            if ($drawn) {
                file_input('Report');
            }
        };

        $first = $this->post($tool, [], files: ['report' => $this->arrived('report.csv', "a,b\n1,2\n")]);
        self::assertCount(1, $this->stored());

        $drawn = false;
        $this->refreshed($tool, $this->form($first));
        self::assertCount(1, $this->stored(), 'the file is still there');

        $this->post($tool, $this->form($first));
        self::assertCount(0, $this->stored(), 'a run of the user gives it up, as always');
    }

    public function testItBringsNoFile(): void
    {
        // The page script sends no file with a refresh. A request that does all the same is not listened to.
        $file = 'not looked at yet';
        $tool = function () use (&$file): void {
            refresh(30);
            $file = file_input('Report');
        };

        $response = $this->post($tool, [], ['X-Script-Ui' => 'refresh'], ['report' => $this->arrived('report.csv', "a,b\n1,2\n")]);

        self::assertSame(200, $response->status);
        self::assertNull($file);
        self::assertCount(0, $this->stored());
    }

    public function testWhatAButtonSaidIsMarkedSoThatARefreshDoesNotTakeItAway(): void
    {
        $tool = function (): void {
            refresh(30);
            info('Always there.');
            if (button('Retry')) {
                success('Retried 12 jobs.');
            }
            if (button('Retry and reload')) {
                success('Retried and reloaded.');
                rerun();
            }
        };
        $result = '//*[contains(@class,"sui-callout") and @data-sui-result]';

        self::assertSame(0, $this->hits($this->get($tool), $result), 'a message of every run is no result');

        $response = $this->post($tool, ['_click' => 'retry']);
        self::assertSame(['Retried 12 jobs.'], $this->texts($response, $result));

        // After rerun() the message is shown once more, at the top of the reloaded page.
        self::assertSame(303, $this->post($tool, ['_click' => 'retry-and-reload'])->status);
        self::assertSame(['Retried and reloaded.'], $this->texts($this->get($tool), $result));
        self::assertSame(0, $this->hits($this->get($tool), $result));
    }

    public function testTheLineSpeaksTheLanguageOfThePage(): void
    {
        $arabic = $this->get(function (): void {
            title('لوحة الطلبات');
            refresh(30);
        });
        self::assertStringStartsWith('آخر تحديث ', $this->texts($arabic, self::LINE . '/span[@class="sui-refresh-time"]')[0]);
        self::assertSame('إيقاف مؤقت', $this->attr($arabic, self::LINE . '/button', 'data-sui-pause'));
        self::assertSame('متابعة', $this->attr($arabic, self::LINE . '/button', 'data-sui-resume'));
        self::assertSame('متوقف', $this->attr($arabic, self::LINE . '/span[@role="status"]', 'data-sui-text'));

        $this->config = new \Runio\ScriptUi\Config(auth: static fn (): string => 'tester', debug: true, language: 'de');
        $german = $this->get(function (): void {
            refresh(30);
        });
        self::assertStringStartsWith('Aktualisiert um ', $this->texts($german, self::LINE . '/span[@class="sui-refresh-time"]')[0]);
        self::assertSame('Anhalten', $this->attr($german, self::LINE . '/button', 'data-sui-pause'));
        self::assertSame('Fortsetzen', $this->attr($german, self::LINE . '/button', 'data-sui-resume'));
        self::assertSame('Angehalten', $this->attr($german, self::LINE . '/span[@role="status"]', 'data-sui-text'));
    }
}
