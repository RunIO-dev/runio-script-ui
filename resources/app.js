// Script UI. Reruns the page without a full reload.
//
// Without this script the page still works: it is one ordinary form, and every
// change needs a press on a button. With it, changing an input or pressing a
// button sends the form in the background and swaps in the new page content.
(() => {
  'use strict';

  const form = document.getElementById('sui-form');
  const body = document.getElementById('sui-body');
  const status = document.getElementById('sui-status');
  // A browser that lacks one of these keeps the plain form.
  if (!form || !body || !window.fetch || !window.AbortController || !window.ResizeObserver) {
    return;
  }

  // Never read a property from the form itself: a field named "action" or
  // "contains" would be returned instead of the real property.
  const address = Element.prototype.getAttribute.call(form, 'action') || location.href;
  const root = document.documentElement;

  let serial = 0;          // number of the newest request
  let stop = null;         // stops the request that is on its way
  let acting = false;      // a button request is on its way: it is never dropped
  let queued = false;      // an input changed while a button request was on its way
  let dirty = false;       // the user typed since the last request
  let pointerDown = false;
  let composing = false;   // a keyboard is putting a character together (Chinese, Japanese, Korean, some phones)
  let waiting = null;      // a response that arrived while the mouse button was held or a character was composed
  let swapping = false;    // the content of the page is being replaced
  let busyTimer = 0;
  let pauseTimer = 0;      // a run that waits until the user pauses: see soon()
  let reportTimer = 0;     // a report of how the parts of the page stand waits to be sent: see report()
  const turned = new Map(); // parts the user opened or closed since the last request left: name of the field => what it says now
  let stepping = false;    // an arrow key is down: the change that follows comes from it, not from a click
  let kept = new WeakSet(); // what the user moved sideways and the last answer left in its place: see show()

  // For pages that run again by themselves: see refresh() further down.
  let refreshTimer = 0;     // the wait for the next run that the page starts by itself: see plan()
  let refreshEvery = 0;     // seconds between two of them, as the page asks for; 0: it does not ask
  let stopAfter = 0;        // seconds with nobody there after which they stop; 0: never
  let paused = false;       // they stand still: the user said so, or nobody was there for that long
  let missed = false;       // one was due while the page was out of sight
  let failures = 0;         // runs in a row that failed: each one makes the wait longer
  let unread = false;       // the page shows news that the user may not have seen: see hold()
  let touched = Date.now(); // when the user last did something on the page
  let seen = touched;       // when somebody was last there: a touch, or the page coming into view
  let picking = false;      // a list of options or a calendar was opened, and nothing was chosen yet
  let fresh = null;         // the answer to a run of the page's own that waits until the user rests

  const isText = (el) => el instanceof HTMLTextAreaElement
    || (el instanceof HTMLInputElement && /^(text|search|email|url|tel|password)$/.test(el.type));
  const isTyped = (el) => isText(el) || (el instanceof HTMLInputElement && el.type === 'number');
  const isFile = (el) => el instanceof HTMLInputElement && el.type === 'file';

  const busy = (on) => {
    clearTimeout(busyTimer);
    if (on) {
      // Only show the bar when the answer takes a moment.
      busyTimer = setTimeout(() => root.classList.add('sui-busy'), 150);
      body.setAttribute('aria-busy', 'true');
    } else {
      root.classList.remove('sui-busy');
      body.removeAttribute('aria-busy');
    }
  };

  const offline = (on) => {
    let note = document.getElementById('sui-offline');
    if (!on) {
      if (note) note.remove();
      return;
    }
    if (!note) {
      note = document.createElement('div');
      note.id = 'sui-offline';
      note.className = 'sui-callout sui-error';
      note.setAttribute('role', 'alert');
      body.prepend(note);
    }
    // The page brought this text along, in its own language.
    note.textContent = body.getAttribute('data-sui-offline') || 'The server did not answer.';
  };

  // Where the user is: the element with the focus, what they typed into it while
  // a request was on its way, and where to go if that element is gone afterwards.
  const place = (element) => {
    if (!element || !element.id || !body.contains(element)) return null;
    const kept = {
      id: element.id,
      fallback: element.getAttribute('data-sui-return'),
      value: dirty && isTyped(element) ? element.value : null,
      start: null,
      end: null,
    };
    if (isText(element)) {
      try {
        kept.start = element.selectionStart;
        kept.end = element.selectionEnd;
      } catch (error) { /* this kind of input has no caret */ }
    }
    return kept;
  };

  // Puts the keyboard focus back where it was before the content was swapped.
  const refocus = (keep) => {
    const asked = body.querySelector('[data-sui-focus]');
    if (asked) {
      asked.focus();
      return;
    }
    if (!keep) return;

    let again = document.getElementById(keep.id);
    if ((!again || again.disabled) && keep.fallback) {
      // The question was answered and is gone: back to the button it belonged to.
      // Or the last page of a table is reached and "Next" cannot be pressed any
      // more: on to "Previous".
      again = document.getElementById(keep.fallback);
    }
    if (again && body.contains(again)) {
      if (keep.value !== null && again.id === keep.id) again.value = keep.value;
      again.focus({ preventScroll: true });
      if (keep.start !== null && again.id === keep.id) {
        try {
          again.setSelectionRange(keep.start, keep.end);
        } catch (error) { /* see above */ }
      }
    }
  };

  // Swapping the content says nothing to a screen reader. When the user is in a
  // table, its status line is written into a place that is read out when it
  // changes: "Rows 26 to 50 of 80". In a field for files it is the names of the
  // files the field holds now.
  const announce = () => {
    if (!status) return;
    const focused = document.activeElement;
    const table = focused ? focused.closest('.sui-table-block, .sui-field-file') : null;
    const lines = table ? Array.from(table.querySelectorAll('[data-sui-status]'), (line) => line.textContent.trim()) : [];
    const text = lines.join(' ');
    if (status.textContent !== text) status.textContent = text;
  };

  // A part that opens and closes (expander) keeps the state the user gave it.
  // The server only knows the state that the last request carried.
  const expanders = () => body.querySelectorAll('details.sui-expander');
  const stateOf = (box) => box.querySelector(':scope > input[type="hidden"]');

  // Tabs: every part is in the page, and one of them is in view. Which one, a
  // hidden field says, as for an expander.
  const tabGroups = () => body.querySelectorAll('.sui-tabs');
  const headersOf = (group) => Array.from(group.querySelectorAll(':scope > [role="tablist"] > [role="tab"]'));
  const openIn = (group) => headersOf(group).find((header) => header.getAttribute('aria-selected') === 'true');

  // The line of headers can be wider than the page. It says at which of its
  // ends more headers stand out of sight, and the style sheet lets it fade out there.
  const edges = (list) => {
    const hidden = list.scrollWidth - list.clientWidth;
    // On a page from right to left the line starts at the right and counts down from 0.
    const moved = Math.abs(list.scrollLeft);
    list.classList.toggle('sui-more-before', moved > 1);
    list.classList.toggle('sui-more-after', moved < hidden - 1);
  };

  // A header is kept this far from an end where the line goes on. It stays clear
  // of the fading, and a little of the header next to it shows that there is more.
  const PEEK = 32;

  // Moves the header into sight, without moving the page.
  const reveal = (header) => {
    if (!header) return;
    const list = header.parentElement;
    const line = list.getBoundingClientRect();
    const own = header.getBoundingClientRect();
    // At the first and the last header the browser stops where the line ends.
    if (own.left < line.left + PEEK) {
      list.scrollLeft -= line.left + PEEK - own.left;
    } else if (own.right > line.right - PEEK) {
      list.scrollLeft += own.right - line.right + PEEK;
    }
    edges(list);
  };

  const putInView = (header) => {
    const group = header.closest('.sui-tabs');
    headersOf(group).forEach((other) => {
      const open = other === header;
      other.setAttribute('aria-selected', open ? 'true' : 'false');
      const panel = document.getElementById(other.getAttribute('aria-controls') || '');
      if (panel) panel.hidden = !open;
    });
    const field = stateOf(group);
    if (field) field.value = header.getAttribute('data-sui-tab');
    reveal(header);
  };

  // The room for a line of headers changes: the window does, or the part of the
  // page that holds the line comes into sight. Then the open header is brought
  // into sight again, and the line says anew at which ends it goes on.
  const watcher = new ResizeObserver((lines) => {
    lines.forEach((line) => {
      if (kept.delete(line.target)) {
        // The line was put back where the user had moved it. It is not moved again.
        edges(line.target);
      } else {
        reveal(openIn(line.target.parentElement));
      }
    });
  });

  // Watches the lines that are on the page now, and lets go of the ones that
  // were replaced. Each line is looked at once right away.
  const watchTabs = () => {
    watcher.disconnect();
    body.querySelectorAll('.sui-tab-list').forEach((list) => watcher.observe(list));
  };

  // A wide table and a long line of headers can be moved sideways. Their frames
  // carry a name, so that the new page can be moved to where the old one was.
  // A frame that is out of sight (behind a closed tab) has no place to keep. It
  // is left out, so that its line finds the open header when it comes into sight.
  const frames = () => Array.from(body.querySelectorAll('[data-sui-scroll]')).filter((frame) => frame.clientWidth > 0);

  const show = ({ html, title, origin, failed, own }) => {
    const keep = origin || place(document.activeElement);
    const moved = new Map();
    frames().forEach((frame) => moved.set(frame.getAttribute('data-sui-scroll'), frame.scrollLeft));

    // Some browsers say that the field with the focus lost it when it is replaced.
    // The user did not leave the field, and it gets the focus back below.
    swapping = true;
    try {
      body.innerHTML = html;
    } finally {
      swapping = false;
    }
    if (title) document.title = title;

    // What the user opened or closed while the answer was on its way stays that
    // way. The answer was made before the server could know about it.
    expanders().forEach((box) => {
      const field = stateOf(box);
      if (field && turned.has(field.name)) {
        field.value = turned.get(field.name);
        box.open = field.value === '1';
      }
    });
    tabGroups().forEach((group) => {
      const field = stateOf(group);
      const chosen = field && turned.has(field.name)
        ? headersOf(group).find((header) => header.getAttribute('data-sui-tab') === turned.get(field.name))
        : null;
      if (chosen) putInView(chosen);
    });

    // Now the same parts are in sight as before, and each goes back to its place.
    kept = new WeakSet();
    frames().forEach((frame) => {
      const name = frame.getAttribute('data-sui-scroll');
      if (moved.has(name)) {
        frame.scrollLeft = moved.get(name);
        kept.add(frame);
      }
    });
    watchTabs();

    // A list of options that was open is closed now, whatever became of it.
    picking = false;
    // First the line of refresh(): its button is only there once the line is dressed,
    // and it may be the one that gets the focus back.
    plan(failed, own);

    refocus(keep);
    announce();
  };

  // Hands a file to the browser to save, as if the user had followed a download link.
  const save = (blob, name) => {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.hidden = true;
    document.body.append(link);
    link.click();
    link.remove();
    // The browser needs the address until it has started saving.
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  };

  // What the form holds now. A file field in which nothing was chosen sends a
  // file without a name, and that one is left out. A run of the page's own is
  // bare of files altogether: nobody chose to send one.
  const collect = (bare) => {
    const data = new FormData(form);
    let files = false;
    Array.from(data).forEach(([name, value]) => {
      if (typeof value === 'string') return;
      if (bare || (value.name === '' && value.size === 0)) {
        data.delete(name);
      } else {
        files = true;
      }
    });
    return { data, files };
  };

  // How many bytes a request with these fields has, at most. Around every field
  // the browser writes a few lines, and 200 bytes are more than they ever take.
  const weight = (data) => Array.from(data).reduce((sum, [name, value]) => sum + 200 + new Blob([name]).size
    + (typeof value === 'string' ? new Blob([value]).size : value.size + new Blob([value.name]).size), 0);

  // Says under a file field what is wrong with the files, where the server says it too.
  const complain = (input, problems) => {
    const key = input.id.slice(2);
    let note = document.getElementById('e-' + key);
    if (!note) {
      note = document.createElement('p');
      note.id = 'e-' + key;
      note.className = 'sui-field-error';
      note.dir = 'auto';
      (document.getElementById('l-' + key) || input).after(note);
    }
    // A note of the tool that stands here already may be one that is not read out.
    note.setAttribute('role', 'alert');

    // File names are text. They never go into the page as markup.
    note.replaceChildren();
    const word = input.getAttribute('data-sui-error');
    if (word) {
      // For somebody who does not see that the note is red. It is read out, and not shown.
      const read = document.createElement('span');
      read.className = 'sui-sr';
      read.textContent = word;
      note.append(read, ' ');
    }
    problems.forEach((problem, index) => {
      if (index > 0) note.append(document.createElement('br'));
      note.append(problem);
    });

    // What is wrong is read first, before what the field takes.
    const described = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
    if (!described.includes(note.id)) input.setAttribute('aria-describedby', [note.id].concat(described).join(' '));

    // The field looks, and says, what a field does that the server found wrong.
    // The mark goes with the note, when the page is drawn again.
    input.setAttribute('aria-invalid', 'true');
    const frame = input.closest('.sui-field');
    if (frame) frame.classList.add('sui-field-invalid');

    // This is news for the user, like the answer to a button. A page that runs
    // again by itself must not take it away unread.
    note.setAttribute('data-sui-result', '');
    hold();
  };

  // What is wrong with the files that were chosen, in the words the page brought
  // along. The server checks all of it again. Saying it here saves the user the
  // wait for an upload that would be turned down.
  const unfit = (input) => {
    const files = Array.from(input.files);
    // A file name can hold "$&". As a plain replacement that would be read as a pattern.
    const say = (attribute, name) => (input.getAttribute(attribute) || '').replace('{name}', () => name);

    const room = input.getAttribute('data-sui-room');
    if (room !== null && files.length > Number(room)) return [say('data-sui-too-many', '')];

    const max = Number(input.getAttribute('data-sui-max'));
    const large = max > 0 ? files.filter((file) => file.size > max) : [];
    if (large.length > 0) return large.map((file) => say('data-sui-too-large', file.name));

    // PHP throws a request away that is too large as a whole, with everything in it.
    const total = Number(input.getAttribute('data-sui-total'));
    return total > 0 && weight(collect().data) > total ? [say('data-sui-refused', '')] : [];
  };

  // The files did not get through. They leave their fields, and each field says
  // what became of them. Files that stayed would travel again with every later
  // request, whatever it is about, and lock the page for the whole upload each time.
  const handBack = (attribute) => {
    body.querySelectorAll('input[type="file"]').forEach((input) => {
      const names = Array.from(input.files, (file) => file.name);
      if (names.length === 0) return;

      const text = input.getAttribute(attribute) || '';
      input.value = '';
      complain(input, text.includes('{name}') ? names.map((name) => text.replace('{name}', () => name)) : [text]);
    });
  };

  // Opens the page again after a request that left it as it was, and puts the
  // focus back. A locked page takes the focus away from whatever had it.
  const unlock = (origin) => {
    body.inert = false;
    const from = origin && document.getElementById(origin.id);
    if (from && body.contains(from)) from.focus({ preventScroll: true });
  };

  // Pages that run again by themselves: refresh()
  //
  // The page says in a line how often it wants to be run again. The waiting is
  // done here. A run of the page's own is sent like any other. But nobody asked
  // for it, so it never gets in the way: it does not start under the user's
  // hands, and its answer is not put in under them either.
  const QUIET = 2000;     // the user rested for this long before the page may change by itself (all four in milliseconds)
  const AWAY = 60000;     // an open list or marked text that was left this long ago holds nothing back
  const READ = 10000;     // news stays for at least this long once the user is back
  const LONGEST = 300000; // failures make the wait longer, up to this

  const isPicker = (el) => el instanceof HTMLSelectElement
    || (el instanceof HTMLInputElement && /^(date|time|datetime-local|month|week|color)$/.test(el.type));

  // Text of the page is marked, to be copied for example. A new page would undo that.
  const marked = () => {
    const selection = document.getSelection();
    return Boolean(selection) && !selection.isCollapsed && body.contains(selection.anchorNode);
  };

  // Nothing of the user's is under way that a new page would take from them: no
  // text half typed, no button or key held, no question that waits for its
  // answer, no hand on the mouse. And no list open and no text marked, unless
  // that was left behind a minute ago.
  const calm = () => {
    if (dirty || composing || pointerDown || body.querySelector('.sui-confirm')) return false;
    const rested = Date.now() - touched;
    return rested >= QUIET && (rested >= AWAY || (!picking && !marked()));
  };

  const two = (number) => String(number).padStart(2, '0');

  // The line that refresh() draws: the time on the clock of the reader, whether
  // the page still runs by itself, and the button that stops and starts that.
  const dress = () => {
    const line = body.querySelector('[data-sui-refresh]');
    if (!line) return;

    // The server wrote the time of its own clock.
    const time = line.querySelector('time');
    const made = new Date(time.dateTime);
    if (!Number.isNaN(made.getTime())) {
      time.textContent = [made.getHours(), made.getMinutes(), made.getSeconds()].map(two).join(':');
    }

    const word = line.querySelector('[role="status"]');
    word.textContent = paused ? word.getAttribute('data-sui-text') : '';

    const button = line.querySelector('button');
    button.textContent = button.getAttribute(paused ? 'data-sui-resume' : 'data-sui-pause');
    button.hidden = false;
  };

  // No run of the page's own is due any more: not after a wait, and not when
  // the page comes into view. What happens next decides anew.
  const halt = () => {
    clearTimeout(refreshTimer);
    missed = false;
  };

  // Starts the wait for the next run of the page's own. Every run that failed
  // doubles it, up to five minutes. It is never shorter than the page asked for.
  const wait = (least) => {
    halt();
    if (!refreshEvery || paused) return;
    const rate = refreshEvery * 1000;
    refreshTimer = setTimeout(tick, Math.max(rate, least, Math.min(rate * 2 ** failures, LONGEST)));
  };

  // The page shows news: what a button did, or that something went wrong. A run
  // of the page's own would take it away. None starts before the user is back
  // and had the time to read it: see touch().
  const hold = () => {
    halt();
    fresh = null;
    unread = true;
  };

  // Every answer says anew whether the page wants to be run again, and how
  // often. An answer without the line of refresh() ends it. One that failed
  // says nothing either way: the rate of before goes on, with longer waits.
  const plan = (failed, own) => {
    const line = body.querySelector('[data-sui-refresh]');
    if (line) {
      refreshEvery = Number(line.getAttribute('data-sui-refresh')) || 0;
      stopAfter = Number(line.getAttribute('data-sui-stop-after')) || 0;
    } else if (!failed) {
      refreshEvery = 0;
    }
    failures = failed ? failures + 1 : 0;
    dress();

    // A run of the user that failed is news too. A run of the page's own that
    // failed is simply tried again.
    if ((failed && !own) || body.querySelector('[data-sui-result]')) {
      hold();
    } else {
      unread = false;
      wait(0);
    }
  };

  // The wait is over.
  const tick = () => {
    clearTimeout(refreshTimer);
    if (document.hidden) {
      // Nobody sees the page. It catches up when it comes into view.
      missed = true;
      return;
    }
    if (stopAfter && Date.now() - seen >= stopAfter * 1000) {
      // Nobody was there for that long. A page that goes on asking keeps its
      // session alive and the server at work, so it stops, and says so.
      paused = true;
      dress();
      return;
    }
    if (stop || !calm()) {
      // Another run is on its way, or the user is in the middle of something.
      refreshTimer = setTimeout(tick, 500);
      return;
    }
    run(null, null, true);
  };

  // The answer to a run of the page's own waits until the user rests. It is
  // never pushed in. A run of the user drops it: their answer is the newer one.
  const later = () => {
    setTimeout(() => {
      if (!fresh) return;
      if (!calm()) {
        later();
        return;
      }
      const page = fresh;
      fresh = null;
      show(page);
    }, 250);
  };

  // The user did something on the page.
  const touch = () => {
    touched = Date.now();
    seen = touched;
    if (!unread) return;

    // They are back. They get the time to read the news, then the page runs again.
    unread = false;
    body.querySelectorAll('[data-sui-result]').forEach((news) => news.removeAttribute('data-sui-result'));
    wait(READ);
  };

  // own: the page starts this run by itself, to show what is new. See tick().
  const run = (submitter, chooser, own = false) => {
    const action = Boolean(submitter && submitter.name);

    // A button is being handled, or a file is on its way. Do not start anything
    // that could drop the answer.
    if (acting) {
      if (!action) queued = true;
      return;
    }

    if (stop) stop.abort();
    stop = new AbortController();
    const id = ++serial;
    clearTimeout(pauseTimer);
    // This request carries how every part stands now, so nothing is left to report.
    clearTimeout(reportTimer);
    turned.clear();
    // The waiting starts anew when the answer to this run is there. And an answer
    // that still waits to be shown is an old one now.
    halt();
    fresh = null;
    // Whoever starts a run is there, also when no mouse and no key was touched for
    // it: software that reads the page to its user changes a field without either.
    if (!own) seen = Date.now();

    // Files travel as they are. Without them the form goes the short way, as text.
    const { data, files } = collect(own);
    if (action) data.append(submitter.name, submitter.value);

    // While a button is being handled the page is locked. A change made now would
    // be overwritten by the answer, and the user would not notice that it was lost.
    // The same goes for a file on its way: its upload is never dropped half way.
    const locked = action || files;
    const origin = locked ? place(submitter) || place(chooser) || place(document.activeElement) : null;
    if (locked) body.inert = true;

    acting = locked;
    dirty = false;
    // Nobody waits for a run of the page's own, so the page does not look busy.
    if (!own) busy(true);

    let failed = false; // the server did not answer, or the tool broke
    let drawn = false;  // the answer is a page: it is shown, or it waits to be

    fetch(address, {
      method: 'POST',
      body: files ? data : new URLSearchParams(data),
      credentials: 'same-origin',
      redirect: 'follow',
      signal: stop.signal,
      // Ask for the content of the page only, without the frame around it. The
      // server is told when nobody asked for the run: it then presses no button
      // and remembers nothing.
      headers: { 'X-Script-Ui': own ? 'refresh' : 'fragment' },
    })
      .then((response) => {
        // A download button was pressed and the answer is the file, not the page.
        const file = response.headers.get('X-Script-Ui-Download');
        return file === null
          ? response.text().then((html) => ({ response, html }))
          : response.blob().then((blob) => ({ response, blob, file }));
      })
      .then(({ response, html, blob, file }) => {
        if (id !== serial) return;

        // The tool marks its answers. Anything else comes from somewhere else: a
        // page to log in on, or a server in front of the tool.
        const fromTool = response.headers.get('X-Script-Ui-Fragment') === '1';
        if (own && (paused || unread)) {
          // While this run of the page's own was on its way, the user stopped the
          // page from changing by itself, or the page got news for them. The
          // answer is not shown. It still says that the server is there again.
          if (fromTool) offline(false);
          return;
        }
        if (own && !fromTool && response.status >= 500) {
          // A server in front of the tool says that it is away, or that it has
          // too much to do. Loaded anew, the page would be that message, with
          // nothing on it that tries again. So this run failed, and the next
          // one is tried later.
          failed = true;
          offline(true);
          return;
        }

        if (blob) {
          let name = 'download';
          try {
            name = decodeURIComponent(file) || name;
          } catch (error) { /* keep the plain name */ }

          offline(false);
          save(blob, name);

          // The page was locked while the file was made. Give the focus back to the button.
          unlock(origin);

          // The file was made from what the form holds now. If the page still shows
          // an older answer, the run below brings it up to date.
          queued = true;
          return;
        }

        if (response.status === 413 && files) {
          // More than a server takes at once. It did not look at the form at all, so
          // the page stays as it is and says so.
          offline(false);
          handBack('data-sui-refused');
          unlock(origin);
          return;
        }

        if (!fromTool) {
          // Not the content of a tool: the session expired or access was taken away.
          // Load the page properly and let it say so.
          location.reload();
          return;
        }

        offline(false);
        let title = '';
        try {
          title = decodeURIComponent(response.headers.get('X-Script-Ui-Title') || '');
        } catch (error) { /* keep the old title */ }
        failed = response.status >= 500;
        const page = { html, title, origin, failed, own };
        body.inert = false;

        // From now on the saved inputs are the truth, not what a link filled in.
        try {
          const landed = new URL(response.url);
          if (landed.origin === location.origin && landed.href !== location.href) {
            history.replaceState(null, '', landed.href);
          }
        } catch (error) { /* keep the address as it is */ }

        // The answer to a button or to an upload is shown at once. The page was
        // locked, so there is no click and no character to lose. And an answer
        // that waits is dropped when a newer request starts: with it would go the
        // news that an action was done, or that a file is on the server.
        drawn = true;
        if (own && !calm()) {
          fresh = page;
          later();
        } else if ((pointerDown || composing) && !locked) {
          // Swapping the page under a pressed mouse button would lose the click.
          // Under a character that is being composed it would lose the character.
          waiting = { id, page };
          setTimeout(() => {
            // A mouse button that never comes up must not hold the page for ever.
            // A character that is being composed may take its time.
            if (waiting && waiting.id === id && !composing) {
              waiting = null;
              if (id === serial) show(page);
            }
          }, 700);
        } else {
          show(page);
        }
      })
      .catch((error) => {
        if (id !== serial || error.name === 'AbortError') return;
        failed = true;
        offline(true);
        // A server in front of PHP that finds a request too large often just closes
        // the connection. That looks the same from here.
        if (files) handBack('data-sui-failed');
        if (locked) unlock(origin);
      })
      .finally(() => {
        if (id !== serial) return;
        acting = false;
        stop = null;
        body.inert = false;
        busy(false);
        if (queued) {
          queued = false;
          run(null);
        } else if (!drawn) {
          // The page stayed as it was: a file was saved, or no answer came.
          plan(failed, own);
        }
      });
  };

  document.addEventListener('submit', (event) => {
    if (event.target !== form) return;
    event.preventDefault();
    run(event.submitter || null);
  });

  // Runs the page once the user pauses, and with one request at a time. A
  // request for every key would queue up on the server, and their answers would
  // be out of date on arrival.
  const soon = (delay) => {
    clearTimeout(pauseTimer);
    pauseTimer = setTimeout(() => {
      if (composing) return;
      if (stop) {
        queued = true;
      } else {
        run(null);
      }
    }, delay);
  };

  // A search box of a table filters while the user types: a moment after the last key.
  const typed = () => soon(300);

  // The arrow keys walk through the options of a group or of a list, and through
  // days and numbers. Every step is a change, so the page waits for a pause here
  // too. The answer that is on its way belongs to an option the user has left.
  // It is dropped: shown, it would put that option back under their fingers.
  const stepped = () => {
    // Never the answer to a button or to an upload: the page is locked while one
    // of those is on its way, and their answers are shown at once and never held.
    if (stop && !acting) {
      // The run that waits below sends the whole form. A run that was queued
      // behind the dropped one has nothing left to bring.
      queued = false;
      stop.abort();
    }
    waiting = null;
    soon(250);
  };

  const isLive = (el) => isText(el) && el.hasAttribute('data-sui-live');

  body.addEventListener('input', (event) => {
    if (isTyped(event.target)) dirty = true;
    if (isLive(event.target)) typed();
  });

  body.addEventListener('compositionstart', () => { composing = true; });
  body.addEventListener('compositionend', (event) => {
    composing = false;
    settle();
    if (isLive(event.target)) typed();
  });

  // A file was chosen or dropped onto its field. It is sent at once, and the
  // page is locked until the answer is there, as it is for a button.
  const chosen = (input) => {
    // The dialog was closed without a choice.
    if (input.files.length === 0) return;

    const problems = unfit(input);
    if (problems.length > 0) {
      input.value = '';
      complain(input, problems);
      return;
    }

    run(null, input);
  };

  // Choices, tick boxes, dates and numbers rerun the page as soon as they change.
  body.addEventListener('change', (event) => {
    if (isFile(event.target)) {
      chosen(event.target);
    } else if (!isText(event.target)) {
      if (stepping) {
        stepped();
      } else {
        run(null);
      }
    }
  });

  // Tells the server how the user left the parts that open and close. Opening one
  // needs no answer. But without a word the server would draw it closed again when
  // the page is loaded the next time. No tool runs for this, and nothing comes back.
  const report = () => {
    clearTimeout(reportTimer);
    reportTimer = setTimeout(() => {
      const data = new URLSearchParams();
      new FormData(form).forEach((value, name) => {
        if (typeof value === 'string' && /^_(token$|open\[|tab\[)/.test(name)) data.append(name, value);
      });

      fetch(address, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        headers: { 'X-Script-Ui': 'layout' },
      }).catch(() => { /* the next request of the page carries the same fields */ });
    }, 300);
  };

  // A part that the user opens or closes says so in a hidden field. The field
  // travels with every request, and the server draws the part the way the user
  // left it. (The event does not bubble.)
  body.addEventListener('toggle', (event) => {
    const box = event.target;
    if (!(box instanceof HTMLDetailsElement) || !box.classList.contains('sui-expander')) return;

    const field = stateOf(box);
    const state = box.open ? '1' : '0';
    // The event also comes for a part that the server drew open. Then nothing changed.
    if (!field || field.value === state) return;

    field.value = state;
    turned.set(field.name, state);
    report();
  }, true);

  // The user chose a tab. Its part is in the page already, so nothing is asked of
  // the server. It only hears which tab is in view now.
  const choose = (header) => {
    const field = stateOf(header.closest('.sui-tabs'));
    const state = header.getAttribute('data-sui-tab');
    if (!field || field.value === state) return;

    putInView(header);
    turned.set(field.name, state);
    report();
  };

  const headerAt = (target) => (target instanceof Element ? target.closest('button[data-sui-tab]') : null);

  // Without this script the header is a button that sends the form.
  body.addEventListener('click', (event) => {
    const header = headerAt(event.target);
    if (!header) return;

    event.preventDefault();
    choose(header);
  });

  // The arrow keys go from header to header, and the tab follows them. On a page
  // that is read from right to left, the next header is the one on the left.
  body.addEventListener('keydown', (event) => {
    const header = headerAt(event.target);
    if (!header || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;

    const headers = headersOf(header.closest('.sui-tabs'));
    const forward = getComputedStyle(header.parentElement).direction === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
    const back = forward === 'ArrowLeft' ? 'ArrowRight' : 'ArrowLeft';
    const at = headers.indexOf(header);
    let next = null;
    if (event.key === forward) {
      next = headers[(at + 1) % headers.length];
    } else if (event.key === back) {
      next = headers[(at - 1 + headers.length) % headers.length];
    } else if (event.key === 'Home') {
      next = headers[0];
    } else if (event.key === 'End') {
      next = headers[headers.length - 1];
    }
    if (!next) return;

    event.preventDefault();
    next.focus();
    choose(next);
  });

  // A header that the keyboard reaches is moved into sight like the open one.
  // Never one that a mouse or a finger is pressing: it would move away from under
  // the press, and the click would land next to it.
  body.addEventListener('focusin', (event) => {
    const header = headerAt(event.target);
    let byKeyboard = false;
    try {
      byKeyboard = Boolean(header) && header.matches(':focus-visible');
    } catch (error) { /* an old browser: it brings the header into sight in its own way */ }
    if (byKeyboard) reveal(header);
  });

  // The user moved the line of headers. (The event does not bubble.)
  body.addEventListener('scroll', (event) => {
    if (event.target instanceof Element && event.target.classList.contains('sui-tab-list')) edges(event.target);
  }, true);

  // The page can change while a file dialog is open: the user typed into a field
  // and went straight on to choose a file, and the answer to the typed text came
  // in behind the dialog. The field that the dialog belongs to is then no longer
  // on the page, and what is chosen in it would reach nobody. Its files are
  // handed to the field that took its place.
  const watched = new WeakSet();
  body.addEventListener('click', (event) => {
    const input = event.target;
    if (!isFile(input) || watched.has(input)) return;
    watched.add(input);

    input.addEventListener('change', () => {
      if (body.contains(input) || input.files.length === 0) return;
      const heir = document.getElementById(input.id);
      if (isFile(heir) && body.contains(heir)) {
        heir.files = input.files;
        chosen(heir);
      }
    });
  });

  // Text reruns the page when the user leaves the field (or presses Enter, which submits).
  body.addEventListener('focusout', (event) => {
    if (swapping) return;
    if (composing) {
      // Not every keyboard says that a character is finished when the field loses
      // the focus half way. A field without the focus composes nothing, and the
      // page must not wait for an end that never comes.
      composing = false;
      settle();
    }
    if (dirty && isText(event.target)) run(null);
  });

  // Shows the answer that was held back, once nothing is in its way any more.
  const settle = () => {
    // The click that belongs to a release is handled first. If it started a
    // new request, the waiting answer is out of date and dropped.
    setTimeout(() => {
      if (pointerDown || composing) return;
      const held = waiting;
      waiting = null;
      if (held && held.id === serial) show(held.page);
    }, 0);
  };

  const release = () => {
    pointerDown = false;
    settle();
  };

  // A file that is dragged over the page: the fields that take one light up, and
  // everywhere else the browser is told that nothing can be dropped. Without that,
  // a file that misses its field is opened in place of the page, and what the
  // user typed is gone.
  const carriesFiles = (event) => Boolean(event.dataTransfer)
    && Array.from(event.dataTransfer.types || []).includes('Files');
  let dragTimer = 0;

  window.addEventListener('dragover', (event) => {
    if (!carriesFiles(event)) return;

    // Nothing says reliably that a drag has left the window. It only stops saying
    // that it is over the page.
    root.classList.add('sui-dragging');
    clearTimeout(dragTimer);
    dragTimer = setTimeout(() => root.classList.remove('sui-dragging'), 500);

    if (!isFile(event.target)) {
      event.preventDefault();
      event.dataTransfer.dropEffect = 'none';
    }
  });

  window.addEventListener('drop', (event) => {
    clearTimeout(dragTimer);
    root.classList.remove('sui-dragging');
    if (carriesFiles(event) && !isFile(event.target)) event.preventDefault();
  });

  window.addEventListener('pointerdown', (event) => {
    pointerDown = true;
    stepping = false;
    // A list of options or a calendar opens under this press. Or the press is
    // somewhere else, and closes one.
    picking = isPicker(event.target);
    touch();
  }, true);
  window.addEventListener('pointerup', release, true);
  window.addEventListener('pointercancel', release, true);

  window.addEventListener('keydown', (event) => {
    stepping = /^(Arrow|Page|Home$|End$)/.test(event.key || '');
    // In a list or a calendar most keys open it or move in it. These three leave it.
    if (isPicker(event.target)) picking = !/^(Escape|Enter|Tab)$/.test(event.key || '');
    touch();
  }, true);
  window.addEventListener('keyup', () => { stepping = false; }, true);
  // A key that is down when the window loses the focus never comes up here.
  window.addEventListener('blur', () => { stepping = false; });

  // A hand on the mouse or on its wheel is busy too. (A finger on the screen presses first.)
  ['pointermove', 'wheel'].forEach((kind) => window.addEventListener(kind, touch, { capture: true, passive: true }));

  // A page that nobody sees does not run. When it comes into view somebody is
  // there again, and a run that was due in the meantime is made up for.
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    seen = Date.now();
    if (missed) {
      missed = false;
      tick();
    }
  });

  // "Pause" lets the page stand still, with what is on it. "Resume" runs it at
  // once, and from then on it runs by itself again.
  body.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-sui-refresh] button') : null;
    if (!button) return;

    paused = !paused;
    dress();
    if (paused) {
      halt();
      fresh = null;
    } else {
      run(null);
    }
  });

  // The page as the server drew it: the header of an open tab may be out of sight.
  watchTabs();
  // And it may ask to be run again.
  plan(false, true);
})();
