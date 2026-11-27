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
  if (!form || !body || !window.fetch || !window.AbortController) {
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
  let stepping = false;    // an arrow key is down: the change that follows comes from it, not from a click

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

  const show = ({ html, title, origin }) => {
    const keep = origin || place(document.activeElement);

    // Some browsers say that the field with the focus lost it when it is replaced.
    // The user did not leave the field, and it gets the focus back below.
    swapping = true;
    try {
      body.innerHTML = html;
    } finally {
      swapping = false;
    }
    if (title) document.title = title;

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
  // file without a name, and that one is left out.
  const collect = () => {
    const data = new FormData(form);
    let files = false;
    Array.from(data).forEach(([name, value]) => {
      if (typeof value === 'string') return;
      if (value.name === '' && value.size === 0) {
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
      note.setAttribute('role', 'alert');
      note.dir = 'auto';
      (document.getElementById('l-' + key) || input).after(note);
    }

    // File names are text. They never go into the page as markup.
    note.replaceChildren();
    problems.forEach((problem, index) => {
      if (index > 0) note.append(document.createElement('br'));
      note.append(problem);
    });

    const described = (input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean);
    if (!described.includes(note.id)) input.setAttribute('aria-describedby', described.concat(note.id).join(' '));
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

  const run = (submitter, chooser) => {
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

    // Files travel as they are. Without them the form goes the short way, as text.
    const { data, files } = collect();
    if (action) data.append(submitter.name, submitter.value);

    // While a button is being handled the page is locked. A change made now would
    // be overwritten by the answer, and the user would not notice that it was lost.
    // The same goes for a file on its way: its upload is never dropped half way.
    const locked = action || files;
    const origin = locked ? place(submitter) || place(chooser) || place(document.activeElement) : null;
    if (locked) body.inert = true;

    acting = locked;
    dirty = false;
    busy(true);

    fetch(address, {
      method: 'POST',
      body: files ? data : new URLSearchParams(data),
      credentials: 'same-origin',
      redirect: 'follow',
      signal: stop.signal,
      // Ask for the content of the page only, without the frame around it.
      headers: { 'X-Script-Ui': 'fragment' },
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

        if (response.headers.get('X-Script-Ui-Fragment') !== '1') {
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
        const page = { html, title, origin };
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
        if ((pointerDown || composing) && !locked) {
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

  window.addEventListener('pointerdown', () => {
    pointerDown = true;
    stepping = false;
  }, true);
  window.addEventListener('pointerup', release, true);
  window.addEventListener('pointercancel', release, true);

  window.addEventListener('keydown', (event) => {
    stepping = /^(Arrow|Page|Home$|End$)/.test(event.key || '');
  }, true);
  window.addEventListener('keyup', () => { stepping = false; }, true);
  // A key that is down when the window loses the focus never comes up here.
  window.addEventListener('blur', () => { stepping = false; });
})();
