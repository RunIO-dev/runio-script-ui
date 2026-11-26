// Script UI. Reruns the page without a full reload.
//
// Without this script the page still works: it is one ordinary form, and every
// change needs a press on a button. With it, changing an input or pressing a
// button sends the form in the background and swaps in the new page content.
(() => {
  'use strict';

  const form = document.getElementById('sui-form');
  const body = document.getElementById('sui-body');
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
  let waiting = null;      // a response that arrived while the mouse button was held
  let busyTimer = 0;

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
    note.textContent = 'The server did not answer. Check that it is running, then try again. If you were logged out, reload the page.';
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

  const show = ({ html, title, origin }) => {
    const keep = origin || place(document.activeElement);

    body.innerHTML = html;
    if (title) document.title = title;

    const asked = body.querySelector('[data-sui-focus]');
    if (asked) {
      asked.focus();
      return;
    }
    if (!keep) return;

    let again = document.getElementById(keep.id);
    if (!again && keep.fallback) {
      // The question was answered and is gone: back to the button it belonged to.
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
      .then((response) => response.text().then((html) => ({ response, html })))
      .then(({ response, html }) => {
        if (id !== serial) return;

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

        if (pointerDown) {
          // Swapping the page under a pressed mouse button would lose the click.
          waiting = { id, page };
          setTimeout(() => {
            if (waiting && waiting.id === id) {
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

  body.addEventListener('input', (event) => {
    if (isTyped(event.target)) dirty = true;
  });

  // Choices, tick boxes, dates and numbers rerun the page as soon as they change.
  body.addEventListener('change', (event) => {
    if (!isText(event.target)) run(null);
  });

  // Text reruns the page when the user leaves the field (or presses Enter, which submits).
  body.addEventListener('focusout', (event) => {
    if (dirty && isText(event.target)) run(null);
  });

  const release = () => {
    pointerDown = false;
    // The click that belongs to this release is handled first. If it started a
    // new request, the waiting answer is out of date and dropped.
    setTimeout(() => {
      const held = waiting;
      waiting = null;
      if (held && held.id === serial) show(held.page);
    }, 0);
  };

  window.addEventListener('pointerdown', () => { pointerDown = true; }, true);
  window.addEventListener('pointerup', release, true);
  window.addEventListener('pointercancel', release, true);
})();
