<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Customer Status Board - Fasmir Motors</title>
    <style>
        body { background-color: #06162f; color: white; margin: 0; padding: 0; overflow: hidden; font-family: 'Instrument Sans', sans-serif; }
        .grid-layout { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1.5rem; padding: 2rem; height: calc(100dvh - 100px); box-sizing: border-box; }
        .column { background: rgba(255, 255, 255, 0.03); border-radius: 1.5rem; border: 1px solid rgba(255, 255, 255, 0.05); padding: 1.5rem; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .col-title { font-size: clamp(1.25rem, 2vw, 2rem); font-weight: 900; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding-bottom: 1rem; border-bottom: 2px solid rgba(255, 255, 255, 0.1); }
        
        .bike-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem; }
        .bike-card { background: rgba(255, 255, 255, 0.1); border-radius: 1rem; padding: 0.875rem; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 0.75rem; animation: fade-in 0.5s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .bike-card > div:first-child { min-width: 0; width: 100%; }
        .bike-number { font-size: clamp(1.125rem, 1.5vw, 1.75rem); font-weight: 900; letter-spacing: 1px; overflow-wrap: anywhere; }
        .bike-customer { margin-top: 0.25rem; font-size: clamp(0.85rem, 1vw, 1rem); font-weight: 600; color: #cbd5e1; overflow-wrap: anywhere; }
        .status-badge { margin-top: auto; max-width: 100%; box-sizing: border-box; padding: 0.5rem 0.75rem; border-radius: 2rem; font-size: clamp(0.7rem, 0.8vw, 0.85rem); font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; overflow-wrap: anywhere; }
        .empty-state { padding: 2rem 1rem; color: #94a3b8; text-align: center; font-size: 1.125rem; }

        .status-badge { background: var(--status-background); color: var(--status-color); border: 1px solid var(--status-border); }
        
        @keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Auto scroll */
        .scroller { overflow: hidden; flex: 1; position: relative; }
        .scroll-content { position: absolute; width: 100%; transition: top 1s linear; }
        #list-processing { top: 0; transition: none; will-change: transform; }

        header { padding: 1.5rem 2rem; background: rgba(0, 0, 0, 0.3); border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; height: 100px; box-sizing: border-box; }
        .logo-text { font-size: 2.5rem; font-weight: 900; font-style: italic; letter-spacing: -1px; }
        .logo-red { color: #ed142b; }
        .clock { font-size: 1.5rem; font-weight: 700; color: #94a3b8; }
        @media (max-width: 700px) {
            body { overflow: auto; }
            header { padding: 1rem; height: auto; min-height: 100px; flex-wrap: wrap; gap: 0.5rem; }
            .logo-text { font-size: 2rem; }
            .grid-layout { grid-template-columns: 1fr; height: auto; padding: 1rem; }
            .column { height: 60dvh; }
        }
    </style>
</head>
<body>
    <header>
        <div class="logo-text"><span class="logo-red">FASMIR</span> MOTORS</div>
        <div>
            <span style="font-size:1.25rem;font-weight:700;color:white;margin-right:2rem;text-transform:uppercase;letter-spacing:3px;">Live Bike Status</span>
            <span class="clock" id="clock">00:00:00</span>
        </div>
    </header>

    <div class="grid-layout">
        <!-- Intakes Column -->
        <div class="column">
            <div class="col-title">
                <span style="color:#60a5fa;">INTAKES</span>
                <span id="count-intakes" style="background:#60a5fa;color:#06162f;padding:0.25rem 1rem;border-radius:1rem;font-size:1.5rem;">0</span>
            </div>
            <div class="scroller" id="scroll-intakes"><div class="scroll-content" id="list-intakes"></div></div>
        </div>

        <!-- Processing Column -->
        <div class="column">
            <div class="col-title">
                <span style="color:#fb923c;">PROCESSING</span>
                <span id="count-processing" style="background:#fb923c;color:#06162f;padding:0.25rem 1rem;border-radius:1rem;font-size:1.5rem;">0</span>
            </div>
            <div class="scroller" id="scroll-processing"><div class="scroll-content" id="list-processing"></div></div>
        </div>

        <!-- Completed Column -->
        <div class="column">
            <div class="col-title">
                <span style="color:#4ade80;">COMPLETED</span>
                <span id="count-completed" style="background:#4ade80;color:#06162f;padding:0.25rem 1rem;border-radius:1rem;font-size:1.5rem;">0</span>
            </div>
            <div class="scroller" id="scroll-completed"><div class="scroll-content" id="list-completed"></div></div>
        </div>
    </div>

    <script>
        // Update Clock
        setInterval(() => {
            document.getElementById('clock').innerText = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }, 1000);

        let autoScrollers = {};
        let processingHtml = null;
        let processingFrame = null;
        let processingOffset = 0;

        const statusColors = {
            'New Intake': '#60a5fa',
            'Received': '#38bdf8',
            'Assigned': '#c4b5fd',
            'Diagnosis': '#e879f9',
            'Waiting for Parts': '#fb923c',
            'In Progress': '#f87171',
            'Washing / Final Check': '#22d3ee',
            'Repair Ready': '#a3e635',
            'Ready for Delivery': '#4ade80',
            'On Hold': '#facc15',
            'Pending Payment': '#f472b6',
            'Billed': '#2dd4bf',
            'Completed': '#86efac',
            'Delivered': '#93c5fd',
            'Cancelled': '#94a3b8',
        };
        const boardStatusLabels = {
            new_intake: 'New Intake', diagnosis: 'Diagnosis',
            waiting_parts: 'Waiting for Parts', in_repair: 'In Progress',
            washing: 'Washing / Final Check', ready: 'Ready for Delivery',
            on_hold: 'On Hold', pending_payment: 'Pending Payment',
        };

        function escapeBoardText(value) {
            const element = document.createElement('span');
            element.textContent = String(value ?? '');
            return element.innerHTML;
        }

        function startProcessingScroll() {
            if (processingFrame !== null) cancelAnimationFrame(processingFrame);
            const container = document.getElementById('scroll-processing');
            const content = document.getElementById('list-processing');
            const cycle = document.createElement('div');
            cycle.innerHTML = processingHtml;
            const repeat = cycle.cloneNode(true);
            repeat.setAttribute('aria-hidden', 'true');
            repeat.style.marginTop = '1rem';
            content.replaceChildren(cycle);
            let previousTime = null;

            function scrollFrame(time) {
                const elapsed = previousTime === null ? 0 : Math.min((time - previousTime) / 1000, 0.1);
                previousTime = time;
                if (container.clientHeight > 0 && cycle.offsetHeight > container.clientHeight) {
                    if (repeat.parentNode !== content) content.appendChild(repeat);
                    const cycleHeight = repeat.offsetTop - cycle.offsetTop;
                    processingOffset = (processingOffset + elapsed * 32) % cycleHeight;
                } else {
                    repeat.remove();
                    processingOffset = 0;
                }
                content.style.transform = `translateY(-${processingOffset}px)`;
                processingFrame = requestAnimationFrame(scrollFrame);
            }

            processingFrame = requestAnimationFrame(scrollFrame);
        }

        function startAutoScroll(id) {
            if (id === 'processing') {
                startProcessingScroll();
                return;
            }
            if (autoScrollers[id]) clearInterval(autoScrollers[id]);
            const container = document.getElementById('scroll-' + id);
            const content = document.getElementById('list-' + id);
            content.style.top = '0px';
            
            let pos = 0;
            autoScrollers[id] = setInterval(() => {
                if (content.offsetHeight > container.offsetHeight) {
                    pos -= 1; // scroll speed
                    if (Math.abs(pos) > (content.offsetHeight - container.offsetHeight + 50)) {
                        pos = 20; // reset
                    }
                    content.style.top = pos + 'px';
                } else {
                    content.style.top = '0px';
                }
            }, 30);
        }

        async function fetchData() {
            try {
                const res = await fetch('{{ route('live-board.data', ['customer_display' => 1]) }}', { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('Board data is unavailable.');
                const data = await res.json();
                
                ['intakes', 'processing', 'completed'].forEach(stage => {
                    const cards = data.groups?.[stage] || [];
                    const cardsHtml = cards.map(card => {
                        const statusLabel = card.database_status || boardStatusLabels[card.status] || String(card.status || '').replaceAll('_', ' ');
                        const statusColor = statusColors[statusLabel] || '#cbd5e1';

                        return `
                            <div class="bike-card">
                                <div>
                                    <div class="bike-number">${escapeBoardText(card.bike_number)}</div>
                                    <div class="bike-customer">${escapeBoardText(card.customer)}</div>
                                </div>
                                <div class="status-badge" style="--status-color:${statusColor};--status-background:${statusColor}26;--status-border:${statusColor}80;">${escapeBoardText(statusLabel)}</div>
                            </div>
                        `;
                    }).join('');
                    const html = cards.length ? `<div class="bike-grid">${cardsHtml}</div>` : '<div class="empty-state">No bikes in this stage</div>';

                    document.getElementById('count-' + stage).innerText = cards.length;
                    const list = document.getElementById('list-' + stage);
                    const changed = stage === 'processing' ? processingHtml !== html : list.innerHTML !== html;
                    if (changed) {
                        if (stage === 'processing') processingHtml = html;
                        list.innerHTML = html;
                        startAutoScroll(stage);
                    }
                });

            } catch (err) {
                console.error("Board fetch error", err);
            }
        }

        fetchData();
        setInterval(fetchData, 10000); // 10s refresh
    </script>
</body>
</html>
