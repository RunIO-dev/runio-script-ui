# What it is for

Every team that runs software has small jobs that somebody inside has to do now and then: refund an order, extend a trial, import a file from a supplier, send an invoice again. Each job is a few inputs, a button and a result.

Such a job seldom gets a page of its own. A page costs a route, a controller, a template, validation and some JavaScript. That is a lot for something that five colleagues use twice a week. So a developer does the job by hand in the database, or writes a console command that nobody else can run.

Script UI makes the page cost one script.

<!--@include: @/diagrams/by-hand.md-->

## Who does what

<!--@include: @/diagrams/use-cases.md-->

- **A developer** writes each tool as one PHP script, says who may open it, and can read later who pressed what.
- **Colleagues** in support, finance or operations open a tool from a list and use it. They never see code.
- **Your application** stays in charge of the login, the rights and the data. Script UI has no users and no database of its own.
- **A worker** of yours does the work that takes long. The tool starts it and shows how far it is.

## What a tool looks like

Most tools are one of four kinds. Each links to a tool of the demo, with a picture and its source.

| Kind | What the script does | In the demo |
|---|---|---|
| Change something | A few inputs, a table to tick rows in, a button that asks before it acts | [Refund orders](/examples/#refund), [Give a coupon](/examples/#coupon), [Ship orders](/examples/#ship) |
| Look something up | Filters, a table, the details of one row | [Customers](/examples/#customers), [Order archive](/examples/#archive) |
| Bring data in or out | An upload that is checked before anything is saved, a download | [Import orders](/examples/#import), [Export orders](/examples/#export) |
| Watch something | Numbers and charts that keep themselves up to date | [Sales dashboard](/examples/#dashboard), [Audit log](/examples/#audit) |

## Is it the right tool?

<!--@include: @/diagrams/right-tool.md-->

Script UI is for a task. In three cases something else fits better.

- **The page is for the public.** Every tool looks like every other tool, and it keeps what was entered in a session. Customers deserve a page of your application.
- **The job is to manage records.** Lists with create, edit and delete, for many kinds of records, are what admin generators such as EasyAdmin, Filament and Nova are built for. They do that with far less code than a tool would need. Many teams will use both: the admin for the records, and a tool for the task that fits no record. A [link can open a tool with its fields filled in](https://github.com/RunIO-dev/runio-script-ui#links-that-fill-in-fields), so the admin can lead to it.
- **The page needs its own design or its own interactions.** A tool takes what the library draws. Where that is not enough, build the page by hand.

## What it does not bring

- No login and no users. `auth:` asks your application who is there.
- No database layer. A tool calls your own code.
- No queue and no worker. A tool starts your job and shows its numbers.
- No theme. Every tool looks like every other tool.

[Safety and limits](/guide/safety) lists what this version cannot do, in detail.

## If you know Streamlit

The idea is the same: a script that runs again from the top on every interaction, and functions that draw something and return a value. This is what differs.

- It is PHP, and it runs inside your application: behind your login, with your classes.
- A run is one ordinary request. No process stays up between two runs, and there is no websocket.
- Nothing lives in memory between two runs. What a tool needs again comes from its inputs, which the session keeps, or from your storage.

Next: [How it works](/guide/how-it-works).
