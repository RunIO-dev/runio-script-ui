# 2. A tool that changes data

A tool that only shows data cannot do much harm. One that refunds orders can. This tutorial walks through two tools of the demo: one that asks before it acts, and one that checks what was typed.

The demo is not part of the Composer package. It runs from a copy of the repository:

```bash
git clone https://github.com/RunIO-dev/runio-script-ui.git
cd runio-script-ui
composer install
php bin/script-ui serve examples/tools
```

## Ask before you act

This is the whole refund tool. `$shop` stands in for your own code.

<<< @/../examples/tools/refund.php{16-20,24-29,33-40}

**Lines 16 to 20: nothing to do yet.** Without an email there is nothing to show. An early `return` ends the page there.

**Lines 24 to 29: the table returns the ticked rows.** They come back exactly as you passed them in. The browser only says which rows are ticked, so `$order['id']` is an id of yours, never something that was typed into a request.

**Line 33: the button.** It is drawn only while something is ticked. `confirm:` makes it ask first, and the question names what will happen:

![The refund tool with two ticked orders. Under the red button "Refund selected" a question asks "Refund 2 orders?" with the buttons "Yes" and "Cancel".](/shots/refund.png){.shot}

`button()` returns `true` only after the user answered yes to exactly that question. When the selection changes in between, the question changes with it, and an old yes does not count.

**Lines 34 to 39: the work.** It runs once. `rerun()` then runs the script again, so that the table above shows the orders that are left. The message survives that:

![The refund tool after the yes: the message "Refunded 2 orders." and one order left in the table.](/shots/refund-done.png){.shot}

## Check what was typed

`field_error()` writes under an input what is wrong with it. `valid()` says whether anything was found wrong in this run.

<<< @/../examples/tools/coupon.php{25-27,31-42,56-60}

![The coupon tool after a press on "Give coupon": a red note under "Customer email" says "Enter the email of a customer.", and one under "Reason" says that more than 20 percent needs a reason.](/shots/coupon.png){.shot}

There are two places to check, and this tool uses both.

**On every run, lines 25 to 27.** The note comes as soon as the user leaves the field, and it stays until the field is right. An empty field is left alone there, or a new page would start full of red.

**Behind the button, lines 31 to 42.** What is missing is asked for when the button is pressed. The first field that is wrong gets the keyboard focus, and software that reads the page out says the note.

**Lines 56 to 60.** `field_error()` does not stop the script. Ask `valid()` before the work is done.

The comment above line 47 names something every such tool has to think of: the form still holds what was typed after the coupon was given, so a second press must not give a second one.

## Who pressed what

Colleagues will press buttons that change data. With `log:` every press and every download reaches a function of yours. This is the setting of the demo:

<<< @/../examples/tools/_config.php{17-26}

An entry says who it was, which tool and which button, whether a question was answered with yes, what every input held in that run, and what the page said was wrong. The demo shows its log in a tool of its own:

![The audit log of the demo: a table with the time, the user, the tool, the button and the inputs of every press.](/shots/audit.png){.shot}

## Rules for a tool that changes data

1. Change data only inside `if (button(...))`. The script also runs when somebody changes a filter.
2. Ask with `confirm:` before something that cannot be undone, and say in the question what will happen.
3. Check behind the button, and ask `valid()` before the work.
4. A button can be pressed twice, and by two people. Where that matters, let the database refuse the second one.
5. Call `rerun()` after the work when the page above the button shows the data.

Next: [Inside your application](/tutorials/your-application).
