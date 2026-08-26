// Cancel booking handler
(function () {
  var btn = document.getElementById('cancel-booking');
  if (!btn) return;
  btn.addEventListener('click', async function () {
    if (!confirm('Точно отменить бронирование? Это действие нельзя отменить.')) return;
    this.disabled = true;
    this.textContent = 'Отменяем…';
    try {
      var ref = this.dataset.ref;
      var token = this.dataset.token;
      var response = await fetch(window.API_BASE + '/cancel-booking.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ref: ref, token: token }),
      });
      var data = await response.json();
      if (!data.ok) throw new Error(data.error || 'Не удалось отменить бронь');
      location.reload();
    } catch (error) {
      alert(error.message || 'Не удалось отменить бронь');
      this.disabled = false;
      this.textContent = 'Отменить бронь';
    }
  });
})();
