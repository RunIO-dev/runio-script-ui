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
  let typingTimer = 0;     // the user is typing into a search box that filters while they type

  const isText = (el) => el instanceof HTMLTextAreaElement
    || (el instanceof HTMLInputElement && /^(text|search|email|url|tel|password)$/.test(el.type));
  const isTyped = (el) => isText(el) || (el instanceof HTMLInputElement && el.type === 'number');

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
  // changes: "Rows 26 to 50 of 80".
  const announce = () => {
    if (!status) return;
    const focused = document.activeElement;
    const table = focused ? focused.closest('.sui-table-block') : null;
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

  const run = (submitter) => {
    const action = Boolean(submitter && submitter.name);

    // A button is being handled. Do not start anything that could drop its answer.
    if (acting) {
      if (!action) queued = true;
      return;
    }

    if (stop) stop.abort();
    stop = new AbortController();
    const id = ++serial;
    clearTimeout(typingTimer);

    const data = new URLSearchParams(new FormData(form));
    if (action) data.append(submitter.name, submitter.value);

    // While a button is being handled the page is locked. A change made now would
    // be overwritten by the answer, and the user would not notice that it was lost.
    const origin = action ? place(submitter) || place(document.activeElement) : null;
    if (action) body.inert = true;

    acting = action;
    dirty = false;
    busy(true);

    fetch(address, {
      method: 'POST',
      body: data,
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
          body.inert = false;
          const pressed = origin && document.getElementById(origin.id);
          if (pressed) pressed.focus({ preventScroll: true });

          // The file was made from what the form holds now. If the page still shows
          // an older answer, the run below brings it up to date.
          queued = true;
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

        if (pointerDown || composing) {
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
        if (id === serial && error.name !== 'AbortError') offline(true);
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

  // A search box of a table filters while the user types: a moment after the
  // last key, and with one request at a time. A request for every key would
  // queue up on the server, and their answers would be out of date on arrival.
  const typed = () => {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
      if (composing) return;
      if (stop) {
        queued = true;
      } else {
        run(null);
      }
    }, 300);
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

  // Choices, tick boxes, dates and numbers rerun the page as soon as they change.
  body.addEventListener('change', (event) => {
    if (!isText(event.target)) run(null);
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

  window.addEventListener('pointerdown', () => { pointerDown = true; }, true);
  window.addEventListener('pointerup', release, true);
  window.addEventListener('pointercancel', release, true);
})();
