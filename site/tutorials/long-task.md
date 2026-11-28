# 5. A task that takes long

An import of fifty thousand rows does not fit into one run of a tool. The browser waits, PHP's time limit ends the script, and nobody sees how far it is. Work like that belongs to a worker: a job in a queue, a cron job, a command. The tool starts the task and then shows how far it is.

<!--@include: @/diagrams/long-task.md-->

The tool and the worker never talk to each other. They share one row in your storage. The worker writes how far it is, and the page reads that row every two seconds.

## The tool of the demo

The export of the demo takes half a minute. Its clock stands in for a worker, and everything else is what a real tool does:

<<< @/../examples/tools/export.php{13-15,17-22,24-27,41-45}

![The export of the demo while it runs: a bar that is about a third full, the words "900 of 2,400 orders" beside it, and a button "Stop the export".](/shots/export.png){.shot}

**Lines 13 to 15.** While the task waits or runs, `refresh(2)` runs the script again every two seconds. When the task is over, the script no longer reaches that line, and the page stands still.

**Lines 17 to 22.** `progress()` without a value says only that work is going on: nobody knows yet how far it is. With two numbers it draws a bar, and `text:` puts your own words beside it in place of the percentage.

**Lines 24 to 27.** Stopping is a wish. The button writes it down, and the worker looks for it between two steps.

**Lines 41 to 45.** The start hands the work over and comes back at once. It writes no message: the bar on the next page says that the task started.

## What the tool shows in each state

<!--@include: @/diagrams/task-states.md-->

The demo has no failed state, because its clock cannot fail. A real task can, so yours needs one.

## What to think of

The rest of this page is the text of the README, word for word.

<!--@include: ../../README.md#long-task-->

That was the last tutorial. [All functions](/reference/functions) has every function with its parameters, and the [examples](/examples/) show ten whole tools.
