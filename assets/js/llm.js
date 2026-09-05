/*  llm.js — LLM interface for the ensemble chat architecture
    All three layers always run. LLM is the final arbiter.          */

window.TravelLLM = (function () {
  let worker = null;
  let ready = false;
  let loading = false;
  let pending = {};
  let nextId = 1;
  let onProgress = null;
  let onReady = null;
  let onError = null;

  const SYSTEM_PROMPT_DECIDE = `Ты — финальный арбитр чат-бота Travel.ru для бронирования отелей.
Тебе даны:
- запрос пользователя
- результаты(regex-парсера) — извлечённые сущности
- результаты(ML-классификатора) — намерение, сущности, ответ, уверенность
- расхождения между слоями (если есть)

Твоя задача — принять решение и вернуть СТРОГО JSON без пояснений:

Если данные полны и ясен ответ:
{
  "action": "answer",
  "text": "Текст ответа пользователю (1-3 предложения)",
  "city": "город или null",
  "price_min": null,
  "price_max": null,
  "checkin": null,
  "checkout": null,
  "guests": null,
  "stars": null,
  "amenities": []
}

Если нужны уточнения:
{
  "action": "ask",
  "question": "Уточняющий вопрос пользователю",
  "city": null,
  "price_min": null,
  "price_max": null
}

Правила:
- Если город не определён ни одним слоем — action: "ask", question: "В каком городе ищете отель?"
- Если есть расхождения между слоями — выбирай более надёжный источник
- Если regex и ML согласны — подтверждай их результат
- Если ML confidence > 0.8 и regex согласен — подтверждай
- Отвечай на русском языке пользователя
- Не выдумывай факты — если данных нет, ask
- Краткость — 1-3 предложения в text`;

  function init(opts) {
    onProgress = opts.onProgress || null;
    onReady = opts.onReady || null;
    onError = opts.onError || null;

    if (worker) return;
    worker = new Worker('/assets/js/llm-worker.js');
    worker.onmessage = handleWorkerMessage;
    worker.onerror = function (e) {
      console.warn('[LLM] Worker error:', e.message);
      if (onError) onError(e.message);
    };
  }

  function handleWorkerMessage(e) {
    const msg = e.data;
    const p = pending[msg.id];
    if (!p) return;

    switch (msg.type) {
      case 'progress':
        if (onProgress) onProgress(msg.progress, msg.note);
        break;
      case 'ready':
        ready = true;
        loading = false;
        if (p.resolve) p.resolve();
        delete pending[msg.id];
        if (onReady) onReady();
        break;
      case 'result':
        if (p.resolve) p.resolve(msg.text);
        delete pending[msg.id];
        break;
      case 'error':
        if (p.reject) p.reject(new Error(msg.error));
        delete pending[msg.id];
        break;
      case 'status':
        if (p.resolve) p.resolve(msg);
        delete pending[msg.id];
        break;
    }
  }

  function send(type, data) {
    return new Promise((resolve, reject) => {
      const id = nextId++;
      pending[id] = { resolve, reject };
      worker.postMessage({ type, id, ...data });
    });
  }

  function load() {
    if (ready) return Promise.resolve();
    if (loading) return Promise.resolve();
    loading = true;
    return send('load', {});
  }

  function generate(text, opts) {
    if (!ready) return Promise.reject(new Error('LLM not loaded'));
    return send('generate', {
      text,
      systemPrompt: (opts && opts.system) || SYSTEM_PROMPT_DECIDE,
      maxTokens: (opts && opts.maxTokens) || 256,
      temperature: (opts && opts.temperature) || 0.3,
    });
  }

  function decide(userQuery, regexEntities, mlResult, discrepancy) {
    if (!ready) return Promise.reject(new Error('LLM not loaded'));

    const contextParts = [
      'Запрос пользователя: ' + userQuery,
      '',
      '--- Слой 1 (regex) ---',
      JSON.stringify(regexEntities || {}),
      '',
      '--- Слой 2 (ML) ---',
      'Намерение: ' + (mlResult ? mlResult.intent : 'неизвестно'),
      'Уверенность: ' + (mlResult ? mlResult.confidence : 0),
      'Сущности: ' + JSON.stringify(mlResult ? mlResult.entities || {} : {}),
      'Ответ ML: ' + (mlResult ? mlResult.answer_ml : ''),
    ];

    if (discrepancy) {
      contextParts.push('', '--- РАСХОЖДЕНИЕ ---');
      contextParts.push(discrepancy);
    }

    return generate(contextParts.join('\n'), {
      system: SYSTEM_PROMPT_DECIDE,
      maxTokens: 256,
      temperature: 0.2,
    }).then(function (raw) {
      try {
        const jsonMatch = raw.match(/\{[\s\S]*\}/);
        if (!jsonMatch) return null;
        return JSON.parse(jsonMatch[0]);
      } catch (_) {
        return null;
      }
    });
  }

  function getStatus() {
    if (!worker) return Promise.resolve({ ready: false, loading: false, progress: 0 });
    return send('status', {});
  }

  return {
    init: init,
    load: load,
    generate: generate,
    decide: decide,
    getStatus: getStatus,
    isReady: function () { return ready; },
    isLoading: function () { return loading; },
  };
})();
