// Чат-ассистент: подбор отелей, быстрые действия и пошаговая бронь
(function () {
  const widget = document.getElementById('chat-widget');
  const toggleBtn = document.getElementById('chat-toggle');
  const closeBtn = document.getElementById('chat-close');
  const form = document.getElementById('chat-form');
  const input = document.getElementById('chat-input');
  const messages = document.getElementById('chat-messages');
  const chips = document.getElementById('chat-chips');

  if (!widget || !chips) return;

  const QUICK_CHIPS = [
    'Отель у моря в Сочи',
    'Отели до 10 000 ₽',
    'Лучшие отели',
    'Как получить промокод?',
    'Забронировать отель',
  ];

  const TYPE_LABELS = { beach: 'пляжный', mountain: 'горный', city: 'городской' };

  let flow = null;
  let busy = false;
  // Компактная структурированная память диалога. Никакой модели или внешнего API:
  // сохраняются только факты, которые пользователь уже сообщил в текущей вкладке.
  const emptyCtx = { hotel: '', hotelId: 0, city: '', state: 'IDLE', suggestions: [], filters: {}, profile: {} };
  let ctx = { ...emptyCtx };
  try {
    const saved = JSON.parse(sessionStorage.getItem('travel_chat_context_v2') || 'null');
    if (saved && typeof saved === 'object') ctx = { ...emptyCtx, ...saved, filters: saved.filters || {}, profile: saved.profile || {} };
  } catch (e) {}

  function saveCtx() {
    try { sessionStorage.setItem('travel_chat_context_v2', JSON.stringify(ctx)); } catch (e) {}
  }

  function remember(data) {
    if (!data || typeof data !== 'object') return;
    if (data.reset) ctx = { ...emptyCtx, filters: {}, profile: {}, suggestions: [] };
    if (data.state) ctx.state = data.state;
    if (data.filters) {
      ctx.filters = { ...data.filters };
      ctx.city = data.filters.city || '';
    }
    if (Array.isArray(data.suggestions)) ctx.suggestions = data.suggestions.slice(0, 6);
    // When server returns null hotel/hotelId, clear them (e.g. on IDLE state)
    ctx.hotel = data.hotel ? String(data.hotel) : '';
    ctx.hotelId = data.hotelId ? Number(data.hotelId) : 0;
    if (data.prefill) {
      ['name', 'phone', 'guests', 'checkin', 'checkout', 'nights'].forEach((key) => {
        if (data.prefill[key] !== undefined && data.prefill[key] !== null) ctx.profile[key] = data.prefill[key];
      });
    }
    saveCtx();
  }

  function scrollToBottom() {
    messages.scrollTop = messages.scrollHeight - messages.clientHeight;
  }

  function open() {
    widget.classList.remove('hidden');
    widget.classList.add('flex');
    toggleBtn.classList.add('hidden');
    if (messages.childElementCount === 0) {
      addMsg('bot', 'Привет! Я ассистент Travel.ru. Подберу отель по городу, цене и удобствам и оформлю бронь прямо в чате. Что ищете?');
      addChips(QUICK_CHIPS);
    }
    setTimeout(() => { if (input) input.focus(); }, 50);
  }

  function close() {
    widget.classList.add('hidden');
    widget.classList.remove('flex');
    toggleBtn.classList.remove('hidden');
  }

  function addMsg(role, text, isHtml) {
    const wrap = document.createElement('div');
    wrap.className = 'flex ' + (role === 'user' ? 'justify-end' : 'justify-start');
    const bubble = document.createElement('div');
    bubble.className = 'max-w-[80%] whitespace-pre-line rounded-2xl px-3 py-2 ' +
      (role === 'user'
        ? 'rounded-br-sm bg-gradient-to-r from-blue-600 to-teal-500 text-white'
        : 'rounded-bl-sm bg-white text-slate-700 shadow-sm border border-slate-200');
    if (isHtml) bubble.innerHTML = text;
    else bubble.textContent = text;
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    while (messages.children.length > 60) messages.firstElementChild.remove();
    scrollToBottom();
  }

  function addLinkBubble(label, href) {
    const wrap = document.createElement('div');
    wrap.className = 'flex justify-start';
    const a = document.createElement('a');
    a.href = href;
    a.className = 'max-w-[80%] rounded-2xl rounded-bl-sm bg-gradient-to-r from-emerald-500 to-teal-500 px-3 py-2 text-white shadow-sm transition hover:shadow';
    a.textContent = label;
    wrap.appendChild(a);
    messages.appendChild(wrap);
    scrollToBottom();
  }

  function addSuggestions(list) {
    if (!list || list.length === 0) return;
    const wrap = document.createElement('div');
    wrap.className = 'flex flex-col gap-2';
    list.forEach((h) => {
      const a = document.createElement('a');
      a.href = '/hotel.php?id=' + h.id;
      a.className = 'rounded-xl border border-slate-200 bg-white p-3 text-slate-700 shadow-sm transition hover:border-teal-400 hover:shadow';
      const nameDiv = document.createElement('div');
      nameDiv.className = 'font-semibold';
      nameDiv.textContent = h.name;
      a.appendChild(nameDiv);
      const metaDiv = document.createElement('div');
      metaDiv.className = 'text-xs text-slate-500';
      metaDiv.textContent = h.city + ' · ' + h.stars + '\u2605 \u00B7 ' + (TYPE_LABELS[h.type] || h.type);
      a.appendChild(metaDiv);
      const priceDiv = document.createElement('div');
      priceDiv.className = 'mt-1 text-sm font-bold text-teal-600';
      priceDiv.textContent = (typeof window !== 'undefined' && window.travelCurrency ? window.travelCurrency.format(h.price) : new Intl.NumberFormat('ru-RU').format(h.price) + ' \u20BD') + ' / \u043D\u043E\u0447\u044C';
      a.appendChild(priceDiv);
      wrap.appendChild(a);
    });
    const item = document.createElement('div');
    item.className = 'flex justify-start';
    item.appendChild(wrap);
    messages.appendChild(item);
    scrollToBottom();
  }

  // Кнопки-подсказки в отдельной панели: строки отправляются как текст,
  // объекты { label, onClick } выполняют свою логику
  function addChips(list) {
    chips.innerHTML = '';
    if (!list || list.length === 0) return;
    list.forEach((item) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = typeof item === 'string' ? item : item.label;
      b.className = 'rounded-full border border-teal-300 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100';
      b.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        try {
          if (typeof item === 'string') await doSend(item);
          else await item.onClick();
        } finally {
          busy = false;
        }
      });
      chips.appendChild(b);
    });
  }

  function showTyping() {
    const wrap = document.createElement('div');
    wrap.className = 'flex justify-start';
    const bubble = document.createElement('div');
    bubble.className = 'max-w-[80%] rounded-2xl rounded-bl-sm bg-white px-3 py-2 shadow-sm border border-slate-200 chat-typing-bubble';
    const typing = document.createElement('span');
    typing.className = 'chat-typing';
    for (let i = 0; i < 3; i++) { const dot = document.createElement('i'); typing.appendChild(dot); }
    bubble.appendChild(typing);
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    scrollToBottom();
  }
  function removeTyping() {
    const all = messages.querySelectorAll('.chat-typing-bubble');
    const last = all[all.length - 1];
    if (last) last.closest('.justify-start').remove();
    scrollToBottom();
  }

  // ---------- Пошаговая бронь ----------
  // data — ответ chat.php: учитываем prefill (имя/даты/гости из первого сообщения)
  function startFlow(data) {
    const p = (data && data.prefill) || {};
    flow = { step: 'hotel' };
    if (p.name) flow.name = p.name;
    if (p.checkin && p.checkout) {
      flow.checkin = p.checkin;
      flow.checkout = p.checkout;
      flow.nights = p.nights || 1;
    }
    if (p.guests) flow.guests = p.guests;
    if (p.phone) flow.phone = p.phone;
    updateFlowChips();
  }

  // Продвижение флоу: отель -> имя -> телефон -> даты -> гости -> промокод.
  // Пропускает шаги, для которых данные уже известны (префилл).
  function advanceFlow() {
    if (!flow) return;
    if (flow.hotelId == null) {
      flow.step = 'hotel';
      updateFlowChips();
      return;
    }
    if (!flow.name) {
      flow.step = 'name';
      addMsg('bot', 'Под каким именем бронируем?');
      updateFlowChips();
      return;
    }
    if (!flow.phone) {
      flow.step = 'phone';
      addMsg('bot', 'Спасибо, ' + flow.name + '! Укажите телефон для подтверждения брони.');
      updateFlowChips();
      return;
    }
    if (!flow.checkin || !flow.checkout) {
      flow.step = 'dates';
      addMsg('bot', 'На какие даты? Напишите заезд и выезд, например «20.12 и 24.12» или «20-24 декабря».');
      updateFlowChips();
      return;
    }
    if (!flow.guests) {
      flow.step = 'guests';
      addMsg('bot', 'Сколько гостей?');
      updateFlowChips();
      return;
    }
    flow.step = 'promo';
    addMsg('bot', 'Есть промокод? Если да — введите его, иначе нажмите «Нет промокода».');
    updateFlowChips();
  }

  async function pickHotel(id) {
    try {
      const res = await fetch('/api/hotels.php');
      const data = await res.json();
      const h = (data.hotels || []).find((x) => x.id === id);
      if (!h) throw new Error('no hotel');
      flow.hotelId = id;
      flow.hotelName = h.name;
      flow.hotelPrice = h.price;
      ctx.hotel = h.name;
      ctx.hotelId = id;
      ctx.city = h.city;
      saveCtx();
      advanceFlow();
    } catch (err) {
      addMsg('bot', 'Отель с ID ' + id + ' не найден. Попробуйте другой номер или ссылку.');
    }
  }

  function addHotelChips(list) {
    chips.innerHTML = '';
    if (list && list.length) ctx.suggestions = list;
    list.forEach((h) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = h.name;
      b.className = 'max-w-full truncate rounded-full border border-teal-300 bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-700 transition hover:bg-teal-100';
      b.title = h.city + ' · ' + (typeof window !== 'undefined' && window.travelCurrency ? window.travelCurrency.format(h.price) : h.price + ' ₽') + ' / ночь';
      b.addEventListener('click', async () => {
        if (busy) return;
        busy = true;
        try {
          addMsg('user', h.name);
          await pickHotel(h.id);
        } finally {
          busy = false;
        }
      });
      chips.appendChild(b);
    });
  }

  function useDefaultDates() {
    const ms = 86400000;
    const a = new Date(Date.now() + 14 * ms);
    const b = new Date(Date.now() + 16 * ms);
    flow.checkin = fmtISO(a);
    flow.checkout = fmtISO(b);
    flow.nights = 2;
    flow.step = 'guests';
    addMsg('user', 'Стандартные даты (+2 нед.)');
    addMsg('bot', 'Сколько гостей?');
    updateFlowChips();
  }

  function setGuests(g) {
    flow.guests = g;
    flow.step = 'promo';
    addMsg('user', g + (g === 1 ? ' гость' : ' гостя'));
    addMsg('bot', 'Есть промокод? Если да — введите его, иначе нажмите «Нет промокода».');
    updateFlowChips();
  }

  function skipPromo() {
    flow.promo = '';
    addMsg('user', 'Нет промокода');
    showConfirm();
  }

  function updateFlowChips() {
    if (!flow) { addChips(QUICK_CHIPS); return; }
    switch (flow.step) {
      case 'hotel':
        if (flow.hotelOptions && flow.hotelOptions.length) addHotelChips(flow.hotelOptions);
        else addChips([]);
        break;
      case 'dates':
        addChips([{ label: 'Стандартные даты (+2 нед.)', onClick: useDefaultDates }]);
        break;
      case 'guests':
        addChips([
          { label: '2 гостя', onClick: () => setGuests(2) },
          { label: '1 гость', onClick: () => setGuests(1) },
          { label: '3 гостя', onClick: () => setGuests(3) },
        ]);
        break;
      case 'promo':
        addChips([{ label: 'Нет промокода', onClick: skipPromo }]);
        break;
      case 'confirm':
        addChips([
          { label: 'Да, всё верно', onClick: confirmBooking },
          { label: 'Изменить данные', onClick: startChange },
        ]);
        break;
      case 'change':
        addChips([{ label: 'Всё верно, подтвердить', onClick: confirmBooking }]);
        break;
      default:
        addChips([]);
    }
  }

  function guestsFromText(t) {
    if (/одноместн/.test(t)) return 1;
    if (/двухместн/.test(t)) return 2;
    if (/тр[её]хместн/.test(t)) return 3;
    if (/на одного|для одного/.test(t)) return 1;
    if (/трое|нас трое|будет трое|втро[её]м/.test(t)) return 3;
    if (/четверо/.test(t)) return 4;
    const m = t.match(/(\d{1,2})\s*(?:гост|человек|персон)/);
    if (m) {
      const g = parseInt(m[1], 10);
      if (g >= 1 && g <= 8) return g;
    }
    if (/\bдвое\b|\bдва\b|\bвдво[её]м\b|на двоих\b/.test(t)) return 2;
    return null;
  }

  // Отправка свободного текста в NLU (вне флоу и как «аварийный выход» из флоу)
  async function askBot(text) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 12000);
    try {
      const res = await fetch('/api/chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text: text.slice(0, 500), context: ctx }),
        signal: controller.signal,
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    } finally {
      clearTimeout(timeout);
    }
  }

  async function flowHandle(text) {
    const t = text.trim().toLowerCase();
    if (t === 'отмена' || t === 'выход' || t === 'стоп') {
      flow = null;
      addMsg('bot', 'Хорошо, отменяем. Если передумаете — просто скажите «забронировать».');
      updateFlowChips();
      return;
    }

    const hotelRef = /hotel\.php\?id=(\d+)/i.test(text) || /отель\s*[№#]?\s*(\d+)/i.test(text) || /id\s*[:=]?\s*(\d+)/i.test(text) || /^(\d+)$/.test(t);
    const questionLike = /[?？]/.test(text) || /\b(сколько|почем|цена|цены|стоит|можно|а можно|что|как|когда|где|зачем|почему|у вас|есть ли|скажи|подскажи|расскажи|покажи|добавить|ещё|гостей|гостя|гость)\b/ui.test(t);

    // Аварийный выход: свободный вопрос (или любое сообщение на шаге выбора отеля)
    // уходит в NLU, а не встречает стену «Не разобрал отель»
    const needsBot = (flow.step === 'hotel' && !hotelRef)
      || (flow.step === 'name' && questionLike && !hotelRef)
      || (flow.step === 'dates' && questionLike && !parseDates(text))
      || (flow.step === 'guests' && questionLike && !/^\d{1,2}$/.test(t))
      || (flow.step === 'promo' && questionLike && !/^нет$/i.test(t) && !/^без/i.test(t))
      || (flow.step === 'confirm' && questionLike && !/^(да|ага|угу|yes|yep|конечно|подтверждаю|изменить|исправить)$/i.test(t));
    if (needsBot) {
      try {
        const d = await askBot(text);
        if (d.ok) {
          const g = guestsFromText(t);
          if (g && flow.guests !== g) {
            flow.guests = g;
            addMsg('bot', 'Учту: ' + g + ' ' + (g === 1 ? 'гость' : 'гостя') + '. Продолжим бронь.');
          }
          remember(d);
          if (d.flow === 'book' && d.suggestions && d.suggestions.length) {
            flow.suggest = true;
            flow.hotelOptions = d.suggestions;
            flow.step = 'hotel';
            if (d.prefill) {
              if (d.prefill.name) flow.name = d.prefill.name;
              if (d.prefill.checkin && d.prefill.checkout) {
                flow.checkin = d.prefill.checkin;
                flow.checkout = d.prefill.checkout;
                flow.nights = d.prefill.nights || 1;
              }
              if (d.prefill.guests) flow.guests = d.prefill.guests;
              if (d.prefill.phone) flow.phone = d.prefill.phone;
            }
            addMsg('bot', d.answer);
            addHotelChips(d.suggestions);
          } else {
            addMsg('bot', d.answer);
            if (d.suggestions && d.suggestions.length) addSuggestions(d.suggestions);
            updateFlowChips();
          }
        } else {
          removeTyping();
          addMsg('bot', 'Ой, что-то пошло не так. Напишите ещё раз или продолжим бронь.');
          updateFlowChips();
        }
      } catch (err) {
        removeTyping();
        addMsg('bot', 'Ой, что-то пошло не так. Проверьте соединение.');
        updateFlowChips();
      }
      return;
    }

    // Смена отеля в середине флоу: «отель 8» / ссылка (кроме шагов confirm/change — там своя логика)
    if (flow.step !== 'hotel' && flow.step !== 'promo' && flow.step !== 'confirm' && flow.step !== 'change' && hotelRef) {
      const m = text.match(/hotel\.php\?id=(\d+)/) || text.match(/отель\s*[№#]?\s*(\d+)/i) || text.match(/id\s*[:=]?\s*(\d+)/i);
      if (m) {
        await pickHotel(parseInt(m[1], 10));
        return;
      }
    }

    switch (flow.step) {
      case 'hotel': {
        const m = text.match(/hotel\.php\?id=(\d+)/) || text.match(/отель\s*[№#]?\s*(\d+)/i) || text.match(/id\s*[:=]?\s*(\d+)/i) || (flow.suggest && /^(\d+)$/.test(t) && { 1: [t] });
        const id = m ? parseInt(m[1], 10) : NaN;
        if (!isNaN(id)) {
          await pickHotel(id);
        } else {
          addMsg('bot', 'Не разобрал отель. Пришлите ссылку на страницу отеля или его ID (например «отель 8»), либо «отмена».');
        }
        break;
      }
      case 'name': {
        const cleanedName = text.trim().replace(/^(?:меня зовут|мо[её] имя|имя)\s+/i, '').trim();
        if (cleanedName.length < 2 || cleanedName.length > 80) { addMsg('bot', 'Напишите имя длиной от 2 до 80 символов.'); break; }
        flow.name = cleanedName;
        advanceFlow();
        break;
      }
      case 'phone': {
        const digits = text.replace(/\D/g, '');
        if (digits.length < 10 || digits.length > 15) { addMsg('bot', 'Введите корректный телефон: от 10 до 15 цифр, например +7 900 123-45-67.'); break; }
        flow.phone = text.trim();
        advanceFlow();
        break;
      }
      case 'dates': {
        let d = parseDates(text);
        if (!d) {
          try {
            const parsed = await askBot(text);
            if (parsed.prefill && parsed.prefill.checkin && parsed.prefill.checkout) {
              d = { checkin: parsed.prefill.checkin, checkout: parsed.prefill.checkout, nights: parsed.prefill.nights || 1 };
            }
          } catch (e) {}
        }
        if (!d) { addMsg('bot', 'Не понял даты. Примеры: «20.12 и 24.12», «20-24 декабря», «завтра на 2 ночи».'); break; }
        if (d.nights < 1) { addMsg('bot', 'Выезд должен быть позже заезда. Попробуйте ещё раз.'); break; }
        flow.checkin = d.checkin;
        flow.checkout = d.checkout;
        flow.nights = d.nights;
        advanceFlow();
        break;
      }
      case 'guests': {
        const g = guestsFromText(t) || parseInt(t, 10);
        if (!g || g < 1 || g > 8) { addMsg('bot', 'Напишите число гостей, например «2».'); break; }
        flow.guests = g;
        advanceFlow();
        break;
      }
      case 'promo': {
        const promo = /^нет$/i.test(t) || /^без/i.test(t) ? '' : text.trim().toUpperCase();
        flow.promo = promo;
        showConfirm();
        break;
      }
      case 'confirm': {
        if (/^(да|ага|угу|yes|yep|конечно|подтверждаю|всё верно|все верно|верно)$/i.test(t)) {
          await confirmBooking();
        } else if (/^(нет|неа|no|не так|не верно|изменить|исправить|другое)$/i.test(t)) {
          startChange();
        } else if (hotelRef) {
          const m = text.match(/hotel\.php\?id=(\d+)/) || text.match(/отель\s*[№#]?\s*(\d+)/i) || text.match(/id\s*[:=]?\s*(\d+)/i);
          if (m) {
            const h = await resolveHotel(parseInt(m[1], 10));
            if (h) { applyHotel(h); addMsg('bot', 'Поменял отель на «' + h.name + '».'); showConfirm(); }
            else addMsg('bot', 'Отель с таким номером не найден.');
          }
        } else {
          addMsg('bot', 'Подтверждаем бронь? Ответьте «да» или скажите, что изменить.');
        }
        break;
      }
      case 'change': {
        const hm = text.match(/hotel\.php\?id=(\d+)/) || text.match(/отель\s*[№#]?\s*(\d+)/i);
        if (hm) {
          const h = await resolveHotel(parseInt(hm[1], 10));
          if (h) { applyHotel(h); addMsg('bot', 'Поменял отель на «' + h.name + '».'); showConfirm(); }
          else addMsg('bot', 'Отель с таким номером не найден.');
          break;
        }
        const pd = parseDates(text);
        if (pd && pd.nights >= 1) {
          flow.checkin = pd.checkin; flow.checkout = pd.checkout; flow.nights = pd.nights;
          addMsg('bot', 'Обновил даты: ' + fmtHuman(pd.checkin) + ' – ' + fmtHuman(pd.checkout) + '.');
          showConfirm(); break;
        }
        const g = guestsFromText(t);
        if (g) { flow.guests = g; addMsg('bot', 'Гостей теперь ' + g + '.'); showConfirm(); break; }
        const digits = text.replace(/\D/g, '');
        if (digits.length >= 10) { flow.phone = text.trim(); addMsg('bot', 'Обновил телефон.'); showConfirm(); break; }
        if (/^(отель|даты|дата|гости|гость|гостей|имя|телефон|город|промокод)$/i.test(t)) {
          addMsg('bot', 'Что изменить? Напишите, например: «отель 8», «даты 20-24», «гостей трое», «имя Алекс», «телефон +7 900 000-00-00».');
          break;
        }
        if (text.trim().length >= 2 && text.trim().length <= 24 && !/\d/.test(text)) {
          flow.name = text.trim();
          addMsg('bot', 'Имя теперь «' + flow.name + '».');
          showConfirm(); break;
        }
        addMsg('bot', 'Не понял, что изменить. Напишите, например: «отель 8», «даты 20-24», «гостей трое», «имя Алекс», «телефон +7 900 000-00-00».');
        break;
      }
    }
  }

  const MONTHS = { января: 0, февраля: 1, марта: 2, апреля: 3, мая: 4, июня: 5, июля: 6, августа: 7, сентября: 8, октября: 9, ноября: 10, декабря: 11 };

  function parseDates(s) {
    const sep = '(?:[\\s,;‑-]+(?:и|с|по)?[\\s,;‑-]+|[\\s,;‑-]+)';
    const iso = s.match(new RegExp('(\\d{4})-(\\d{2})-(\\d{2})' + sep + '(\\d{4})-(\\d{2})-(\\d{2})'));
    if (iso) {
      const a = new Date(+iso[1], +iso[2] - 1, +iso[3]);
      const b = new Date(+iso[4], +iso[5] - 1, +iso[6]);
      return build(a, b);
    }
    const dm = s.match(new RegExp('(\\d{1,2})[.\\/](\\d{1,2})(?:\\.(\\d{2,4}))?' + sep + '(?:с\\s*)?(\\d{1,2})[.\\/](\\d{1,2})(?:\\.(\\d{2,4}))?'));
    if (dm) {
      let y1 = dm[3] ? normYear(dm[3]) : null;
      let y2 = dm[6] ? normYear(dm[6]) : null;
      const a = new Date(y1 || 2000, +dm[2] - 1, +dm[1]);
      const b = new Date(y2 || 2000, +dm[5] - 1, +dm[4]);
      const now = new Date();
      if (!y1) { a.setFullYear(now.getFullYear()); b.setFullYear(now.getFullYear()); }
      return build(a, b);
    }
    const range = s.match(/(\d{1,2})\s*[-\u2013]\s*(\d{1,2})(?:\s+([а-яё]+))?/i);
    if (range) {
      const now = new Date();
      const m = range[3] ? MONTHS[range[3].toLowerCase()] : now.getMonth();
      const a = new Date(now.getFullYear(), m === undefined ? now.getMonth() : m, +range[1]);
      const b = new Date(now.getFullYear(), m === undefined ? now.getMonth() : m, +range[2]);
      if (a.getDate() !== +range[1] || b.getDate() !== +range[2]) return null;
      if (m === undefined && b <= a) b.setMonth(b.getMonth() + 1);
      return build(a, b);
    }
    return null;
  }
  function normYear(y) { y = +y; const c = Math.floor(new Date().getFullYear() / 100) * 100; return y < 100 ? c + y : y; }
  function build(a, b) {
    if (isNaN(a.getTime()) || isNaN(b.getTime())) return null;
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (a < today) return null;
    if (b <= a) return { nights: 0 };
    const nights = Math.round((b - a) / 86400000);
    return {
      checkin: fmtISO(a),
      checkout: fmtISO(b),
      nights: nights,
    };
  }
  function fmtISO(d) {
    const p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  async function submitBooking() {
    try {
      const res = await fetch('/api/booking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          hotel_id: flow.hotelId,
          name: flow.name,
          phone: flow.phone,
          checkin: flow.checkin,
          checkout: flow.checkout,
          guests: flow.guests,
          promo: flow.promo || undefined,
        }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Ошибка');
      const total = new Intl.NumberFormat('ru-RU').format(data.total) + ' ₽';
      addMsg('bot', 'Бронь оформлена! Заявка № ' + data.ref + ' на сумму ' + total +
        (data.discount ? ' (скидка −' + new Intl.NumberFormat('ru-RU').format(data.discount) + ' ₽ по коду ' + data.promo + ')' : '') +
        '. Подтверждение уже отправлено.');
      addLinkBubble('Смотреть подтверждение →', data.confirmation_url || ('/bookings.php'));
      flow = null;
      updateFlowChips();
    } catch (err) {
      addMsg('bot', 'Не получилось оформить: ' + (err.message || 'что-то пошло не так').replace('Error: ', '') + ' Начнём сначала? Напишите «забронировать».');
      flow = null;
      updateFlowChips();
    }
  }

  // ---------- Сводка и подтверждение брони ----------
  const MONTHS_GEN = { '01': 'января', '02': 'февраля', '03': 'марта', '04': 'апреля', '05': 'мая', '06': 'июня', '07': 'июля', '08': 'августа', '09': 'сентября', '10': 'октября', '11': 'ноября', '12': 'декабря' };

  function fmtHuman(iso) {
    const p = String(iso).split('-');
    if (p.length !== 3) return String(iso);
    return parseInt(p[2], 10) + ' ' + (MONTHS_GEN[p[1]] || p[1]) + ' ' + p[0];
  }
  function pluralNights(n) {
    const m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return 'ночь';
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return 'ночи';
    return 'ночей';
  }

  async function resolveHotel(id) {
    try {
      const res = await fetch('/api/hotels.php');
      const data = await res.json();
      return (data.hotels || []).find((x) => x.id === id) || null;
    } catch (err) { return null; }
  }

  function applyHotel(h) {
    flow.hotelId = h.id;
    flow.hotelName = h.name;
    flow.hotelPrice = h.price;
    ctx.hotel = h.name;
    ctx.hotelId = h.id;
    ctx.city = h.city;
    saveCtx();
  }

  function showConfirm() {
    if (!flow) return;
    ctx.profile = {
      ...ctx.profile,
      name: flow.name,
      guests: flow.guests,
      checkin: flow.checkin,
      checkout: flow.checkout,
      nights: flow.nights,
    };
    saveCtx();
    flow.step = 'confirm';
addMsg('bot', 'Подтвердите бронирование:\n' +
      (flow.hotelName || 'Отель ' + flow.hotelId) + '\n' +
      fmtHuman(flow.checkin) + ' – ' + fmtHuman(flow.checkout) + ' · ' + flow.nights + ' ' + pluralNights(flow.nights) + '\n' +
      'Гостей: ' + flow.guests + '\n' +
      flow.name + ' · ' + flow.phone +
      (flow.promo ? '\nПромокод: ' + flow.promo : '') +
      '\n\nВсё верно? Ответьте «да», или скажите, что изменить (например «отель 8», «другие даты»).');
    updateFlowChips();
  }

  function startChange() {
    if (!flow) return;
    flow.step = 'change';
    addMsg('bot', 'Что изменить? Могу поменять отель, даты, гостей, имя или телефон. Например: «отель 8», «даты 20-24», «гостей трое», «телефон +7 900 000-00-00».');
    updateFlowChips();
  }

  async function confirmBooking() {
    if (!flow) return;
    flow.step = 'submit';
    await submitBooking();
  }

  // ---------- Отправка сообщения ----------
  async function doSend(text) {
    if (!text.trim()) return;
    addMsg('user', text);
    input.value = '';
    if (flow) {
      showTyping();
      try { await flowHandle(text); } finally { removeTyping(); }
      return;
    }
    showTyping();
    try {
      const data = await askBot(text);
      if (!data.ok) throw new Error(data.error || 'Ошибка');
      removeTyping();
      addMsg('bot', data.answer);
      remember(data);
      if (data.flow === 'book') {
        startFlow(data);
        if (data.hotelId) {
          // отель уже определён сервером («забронируй отель 8», «забронируй его»)
          flow.hotelId = data.hotelId;
          flow.hotelName = data.hotel;
          ctx.hotel = data.hotel;
          ctx.hotelId = data.hotelId;
          if (data.filters && data.filters.city) ctx.city = data.filters.city;
          const hh = await resolveHotel(data.hotelId);
          if (hh) { flow.hotelPrice = hh.price; if (hh.city) ctx.city = hh.city; }
          advanceFlow();
        } else if (data.suggestions && data.suggestions.length) {
          flow.suggest = true;
          flow.hotelOptions = data.suggestions;
          addMsg('bot', 'Выберите отель кнопкой ниже или напишите «отель 8» / ссылку.');
          addHotelChips(data.suggestions);
        } else {
          updateFlowChips();
        }
      } else {
        if (data.suggestions && data.suggestions.length) addSuggestions(data.suggestions);
        addChips(QUICK_CHIPS);
      }
    } catch (err) {
      removeTyping();
      addMsg('bot', 'Ой, что-то пошло не так. Проверьте соединение и попробуйте ещё раз.');
      addChips(QUICK_CHIPS);
    }
  }

  function send(text) {
    if (busy || !text.trim()) return;
    busy = true;
    doSend(text).finally(() => { busy = false; });
  }

  toggleBtn.addEventListener('click', open);
  closeBtn.addEventListener('click', close);
  widget.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !widget.classList.contains('hidden')) close(); });
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const text = input.value;
    input.value = '';
    send(text);
  });
})();
