// AllianceBB live updates: new posts and topics appear without reloading.
// Uses a Mercure hub (server-sent events) when configured, otherwise polls.
(function () {
  'use strict';

  var config = window.ABB_LIVE || { mode: 'poll', after: 0 };

  // Confirmation prompts for destructive buttons and forms.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var button = e.submitter;
    var message = (button && button.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) e.preventDefault();
  });

  // Copy buttons: <button data-copy="#input-id">
  document.addEventListener('click', function (e) {
    var button = e.target.closest && e.target.closest('[data-copy]');
    if (!button) return;
    var field = document.querySelector(button.getAttribute('data-copy'));
    if (!field) return;
    field.select();
    var done = function () { var t = button.textContent; button.textContent = 'Copied'; setTimeout(function () { button.textContent = t; }, 1500); };
    if (navigator.clipboard) navigator.clipboard.writeText(field.value).then(done, function () { document.execCommand('copy'); done(); });
    else { document.execCommand('copy'); done(); }
  });

  var containers = Array.prototype.slice.call(document.querySelectorAll('[data-live-channel]'));
  if (!containers.length) return;

  function fetchHtml(url) {
    return fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'live' } }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    });
  }

  function toElement(html) {
    var t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstElementChild;
  }

  function highlight(el) {
    el.classList.add('live-new');
    setTimeout(function () { el.classList.remove('live-new'); }, 2500);
  }

  function addPost(container, postId) {
    if (container.getAttribute('data-live-append') !== '1') return Promise.resolve();
    if (document.getElementById('post-' + postId)) return Promise.resolve();
    return fetchHtml('/post/' + postId + '/fragment').then(function (html) {
      if (document.getElementById('post-' + postId)) return;
      var el = toElement(html);
      container.appendChild(el);
      highlight(el);
    }).catch(function () {});
  }

  function replacePost(postId) {
    var old = document.getElementById('post-' + postId);
    if (!old) return;
    fetchHtml('/post/' + postId + '/fragment').then(function (html) {
      var el = toElement(html);
      old.replaceWith(el);
      highlight(el);
    }).catch(function () {});
  }

  function upsertTopic(container, topicId) {
    fetchHtml('/topic/' + topicId + '/row').then(function (html) {
      var el = toElement(html);
      var old = document.getElementById('topic-row-' + topicId);
      if (old) old.remove();
      var empty = container.querySelector('[data-empty]');
      if (empty) empty.remove();
      // New activity goes to the top, below pinned topics.
      var firstUnpinned = container.querySelector('.topic-row:not(.pinned)');
      if (el.classList.contains('pinned') || !firstUnpinned) {
        container.insertBefore(el, container.querySelector('.topic-row'));
      } else {
        container.insertBefore(el, firstUnpinned);
      }
      highlight(el);
    }).catch(function () {});
  }

  function handle(event) {
    var container = containers.filter(function (c) { return c.getAttribute('data-live-channel') === event.channel; })[0];
    if (!container) return;
    var d = event.data || {};
    switch (event.type) {
      case 'post.created': addPost(container, d.post_id); break;
      case 'post.edited': replacePost(d.post_id); break;
      case 'post.deleted': var p = document.getElementById('post-' + d.post_id); if (p) p.remove(); break;
      case 'topic.created':
      case 'topic.bumped': upsertTopic(container, d.topic_id); break;
      case 'topic.deleted': var t = document.getElementById('topic-row-' + d.topic_id); if (t) t.remove(); break;
    }
    document.dispatchEvent(new CustomEvent('abb:live', { detail: event }));
  }

  var channels = containers.map(function (c) { return c.getAttribute('data-live-channel'); });

  if (config.mode === 'mercure' && window.EventSource) {
    var url = new URL(config.hub, window.location.href);
    channels.forEach(function (ch) { url.searchParams.append('topic', config.prefix + ch); });
    var source = new EventSource(url.toString());
    source.onmessage = function (m) {
      try { handle(JSON.parse(m.data)); } catch (e) {}
    };
  } else {
    var after = config.after || 0;
    var delay = 3000;
    var poll = function () {
      var url = '/live/poll?channels=' + encodeURIComponent(channels.join(',')) + '&after=' + after;
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (body) {
        after = body.after || after;
        (body.events || []).forEach(handle);
        delay = document.hidden ? 15000 : 3000;
      }).catch(function () {
        delay = Math.min(delay * 2, 60000);
      }).then(function () { setTimeout(poll, delay); });
    };
    setTimeout(poll, delay);
  }

  // Post replies without leaving the page; the new post shows up immediately.
  var replyForm = document.querySelector('form[data-live-reply]');
  var postsContainer = document.querySelector('.posts[data-live-channel]');
  if (replyForm && postsContainer && postsContainer.getAttribute('data-live-append') === '1' && window.fetch) {
    replyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var button = replyForm.querySelector('button');
      button.disabled = true;
      fetch(replyForm.action, {
        method: 'POST',
        body: new FormData(replyForm),
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (r) { return r.json().then(function (body) { return { ok: r.ok, body: body }; }); })
        .then(function (res) {
          if (!res.ok) { window.alert(res.body.error || 'Could not post your reply.'); return; }
          replyForm.querySelector('textarea').value = '';
          return addPost(postsContainer, res.body.post_id);
        })
        .catch(function () { replyForm.submit(); })
        .then(function () { button.disabled = false; });
    });
  }
})();
