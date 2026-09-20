(() => {
	const bar = document.querySelector('.wfc27-heartbeat');
	if (!bar || typeof wfc27Status === 'undefined') return;
	const fill = bar.querySelector('.wfc27-heartbeat-fill');
	const label = document.getElementById('wfc27-heartbeat-label');
	const lastCell = document.getElementById('wfc27-last-train');
	const nextCell = document.getElementById('wfc27-next-train');
	const counts = document.getElementById('wfc27-queue-counts');
	let last = bar.dataset.last ? Date.parse(bar.dataset.last) : NaN;

	function render() {
		if (!Number.isFinite(last)) {
			fill.style.width = '0%';
			label.textContent = 'Waiting for first train';
			bar.setAttribute('aria-valuenow', '0');
			return;
		}
		const elapsed = Math.max(0, Date.now() - last);
		const percent = Math.min(100, Math.floor(elapsed / 600));
		fill.style.width = `${percent}%`;
		bar.setAttribute('aria-valuenow', String(percent));
		label.textContent = elapsed >= 60000 ? 'Next train is due' : `Next train in about ${Math.ceil((60000 - elapsed) / 1000)} seconds`;
	}

	async function refresh() {
		const data = new URLSearchParams({ action: 'wfc27_status', nonce: wfc27Status.nonce });
		try {
			const response = await fetch(wfc27Status.url, { method: 'POST', credentials: 'same-origin', body: data, cache: 'no-store' });
			if (!response.ok) return;
			const result = await response.json();
			if (!result.success) return;
			last = result.data.last ? Date.parse(result.data.last) : NaN;
			if (Number.isFinite(last)) {
				lastCell.textContent = `${new Date(last).toISOString().slice(0, 19).replace('T', ' ')} UTC`;
				nextCell.textContent = `${new Date(last + 60000).toISOString().slice(0, 19).replace('T', ' ')} UTC`;
			}
			counts.textContent = result.data.counts;
			render();
		} catch (_) { /* Keep the last known heartbeat visible. */ }
	}

	render();
	window.setInterval(render, 250);
	window.setInterval(refresh, 10000);
})();
