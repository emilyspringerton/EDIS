/* EDIS Ask Emily widget JS — loaded via wp_enqueue_script */
(function () {
  'use strict';

  document.querySelectorAll('#edis-ask-widget, .edis-ask').forEach(function (widget) {
    var btn      = widget.querySelector('.edis-ask__submit');
    var tickerEl = widget.querySelector('.edis-ask__ticker');
    var qEl      = widget.querySelector('.edis-ask__question');
    var answerEl = widget.querySelector('.edis-ask__answer');
    var answerTx = widget.querySelector('.edis-ask__answer-text');
    var errorEl  = widget.querySelector('.edis-ask__error');

    if (!btn) return;

    btn.addEventListener('click', function () {
      var question = qEl ? qEl.value.trim() : '';
      var ticker   = tickerEl ? tickerEl.value.trim().toUpperCase() : (edisAsk.defaultTicker || '');

      if (!question) {
        showError('Please enter a question.');
        return;
      }

      btn.disabled = true;
      btn.textContent = 'Thinking…';
      hideAnswer();
      hideError();

      var body = { question: question };
      if (ticker) body.ticker = ticker;

      fetch(edisAsk.apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce':   edisAsk.nonce,
        },
        body: JSON.stringify(body),
      })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, data: d }; }); })
        .then(function (res) {
          if (!res.ok) {
            showError(res.data.error || res.data.message || 'Emily is unavailable right now.');
          } else {
            showAnswer(res.data.answer || '(no answer)');
          }
        })
        .catch(function (err) {
          showError('Request failed: ' + err.message);
        })
        .finally(function () {
          btn.disabled = false;
          btn.textContent = 'Ask Emily';
        });
    });

    function showAnswer(text) {
      answerTx.textContent = text;
      answerEl.style.display = '';
    }
    function hideAnswer() { answerEl.style.display = 'none'; }
    function showError(msg) {
      errorEl.textContent = msg;
      errorEl.style.display = '';
    }
    function hideError() { errorEl.style.display = 'none'; }
  });
})();
