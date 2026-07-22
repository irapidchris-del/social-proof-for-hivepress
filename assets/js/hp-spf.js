(function(){
	const root = document.getElementById('hp-spf-root');
	if (!root || !window.HP_SPF) return;

	function applySettings(cfg){
		root.className = cfg.position || 'bottom-left';
		if (cfg.appearance){
			root.style.setProperty('--hp-spf-bg', cfg.appearance.bg);
			root.style.setProperty('--hp-spf-fg', cfg.appearance.fg);
			root.style.setProperty('--hp-spf-link', cfg.appearance.link);
			root.style.setProperty('--hp-spf-radius', (cfg.appearance.radius||9999) + 'px');
			root.style.setProperty('--hp-spf-shadow', cfg.appearance.shadow);
			root.style.setProperty('--hp-spf-border', cfg.appearance.border);
		}
	}

	function createToast(item, cfg){
		const el = document.createElement('div');
		el.className = 'hp-spf-toast' + (cfg.animation==='fade' ? ' fade-in' : '');
		const img = document.createElement('img');
		img.className = 'hp-spf-img';
		img.alt = '';
		if (item.img) img.src = item.img;
		const content = document.createElement('div');
		content.className = 'hp-spf-content';
		content.innerHTML = item.message;
		const close = document.createElement('button');
		close.className = 'hp-spf-close';
		close.setAttribute('aria-label','Close');
		close.innerHTML = '&times;';
		close.addEventListener('click', ()=> {
			if (el.parentNode===root) root.removeChild(el);
		});
		el.appendChild(img);
		el.appendChild(content);
		el.appendChild(close);
		return el;
	}

	let cfg = null;
	let events = [];
	let idx = 0;
	let timer = null;

	function cycle(){
		if (!events.length) return;
		const active = root.querySelectorAll('.hp-spf-toast').length;
		if (active >= (cfg.max_concurrent||1)) return; // wait
		const item = events[idx % events.length];
		idx++;
		const toast = createToast(item, cfg);
		root.appendChild(toast);
		requestAnimationFrame(()=> toast.classList.add('show'));
		setTimeout(()=>{
			if (toast.parentNode===root) {
				toast.classList.remove('show');
				setTimeout(()=>{ if (toast.parentNode===root) root.removeChild(toast); }, 250);
			}
		}, cfg.display_duration_ms || 5000);
	}

	function start(){
		if (timer) clearInterval(timer);
		timer = setInterval(cycle, cfg.rotation_interval_ms || 7000);
	}

	async function bootstrap(){
		try{
			const res = await fetch(HP_SPF.endpoint, { credentials:'same-origin', cache:'no-store' });
			const data = await res.json();
			if (!data.enabled) return;
			cfg = data;
			events = (data.events||[]).sort((a,b)=> b.ts - a.ts);
			applySettings(cfg);
			start();
		} catch(e) {}
	}

	bootstrap();
})();