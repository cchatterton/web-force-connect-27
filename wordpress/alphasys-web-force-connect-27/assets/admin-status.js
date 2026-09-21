(() => {
	const bar = document.querySelector('.wfc27-heartbeat');
	if (!bar || typeof wfc27Status === 'undefined') return;
	const fill = bar.querySelector('.wfc27-heartbeat-fill');
	const label = document.getElementById('wfc27-heartbeat-label');
	const countdown = document.getElementById('wfc27-train-countdown');
	const counts = document.getElementById('wfc27-queue-counts');
	const waiting = document.getElementById('wfc27-waiting-count');
	const tripRows = document.getElementById('wfc27-trip-rows');
	const tripFilter = document.getElementById('wfc27-trip-filter');
	const tripDay = document.getElementById('wfc27-trip-day');
	const tripHour = document.getElementById('wfc27-trip-hour');
	let last = bar.dataset.last ? Date.parse(bar.dataset.last) : NaN;
	let state = bar.dataset.state;

	function render() {
		if (state === 'paused') {
			fill.style.width = '0%';
			countdown.textContent = '—';
			label.textContent = 'Paused in Salesforce';
			bar.setAttribute('aria-valuenow', '0');
			return;
		}
		if (!Number.isFinite(last)) {
			fill.style.width = '0%';
			countdown.textContent = '01:00';
			label.textContent = 'Waiting for first train';
			bar.setAttribute('aria-valuenow', '0');
			return;
		}
		const elapsed = Math.max(0, Date.now() - last);
		const percent = Math.min(100, Math.floor(elapsed / 600));
		fill.style.width = `${percent}%`;
		bar.setAttribute('aria-valuenow', String(percent));
		const seconds = Math.max(0, Math.ceil((60000 - elapsed) / 1000));
		countdown.textContent = seconds === 60 ? '01:00' : `00:${String(seconds).padStart(2, '0')}`;
		label.textContent = elapsed >= 60000 ? 'Train due' : 'Running';
	}

	async function refresh() {
		const data = new URLSearchParams({ action: 'wfc27_status', nonce: wfc27Status.nonce, trip_filter: tripFilter.value, trip_day: tripDay.value, trip_hour: tripHour.value });
		try {
			const response = await fetch(wfc27Status.url, { method: 'POST', credentials: 'same-origin', body: data, cache: 'no-store' });
			if (!response.ok) return;
			const result = await response.json();
			if (!result.success) return;
			last = result.data.last ? Date.parse(result.data.last) : NaN;
			state = result.data.state;
			counts.textContent = result.data.counts;
			waiting.textContent = `Items waiting to send: ${result.data.waiting}`;
			tripRows.replaceChildren();
			if (!result.data.trips.length) {
				const row = tripRows.insertRow();
				row.insertCell().colSpan = 3;
				row.cells[0].textContent = 'No trips in this period.';
			} else {
				for (const trip of result.data.trips) {
					const row = tripRows.insertRow();
					for (const value of [trip.occurred_at, trip.sent_count, trip.received_count]) row.insertCell().textContent = String(value);
				}
			}
			render();
		} catch (_) { /* Keep the last known heartbeat visible. */ }
	}

	render();
	for (const control of [tripFilter, tripDay, tripHour]) control.addEventListener('change', refresh);
	refresh();
	window.setInterval(render, 250);
	window.setInterval(refresh, 10000);
})();
