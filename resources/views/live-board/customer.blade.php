<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Customer Status Board - Fasmir Motors</title>
    <style>
        body { background-color: #06162f; color: white; margin: 0; padding: 0; overflow: hidden; font-family: 'Instrument Sans', sans-serif; }
        .grid-layout { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem; padding: 2rem; height: calc(100vh - 100px); }
        .column { background: rgba(255, 255, 255, 0.03); border-radius: 1.5rem; border: 1px solid rgba(255, 255, 255, 0.05); padding: 1.5rem; display: flex; flex-direction: column; overflow: hidden; }
        .col-title { font-size: 2rem; font-weight: 900; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; padding-bottom: 1rem; border-bottom: 2px solid rgba(255, 255, 255, 0.1); }
        
        .bike-card { background: rgba(255, 255, 255, 0.1); border-radius: 1rem; padding: 1.5rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; animation: fade-in 0.5s ease; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .bike-card:last-child { margin-bottom: 0; }
        .bike-number { font-size: 2.5rem; font-weight: 900; letter-spacing: 2px; }
        .bike-customer { font-size: 1.25rem; font-weight: 600; color: #cbd5e1; }
        .status-badge { padding: 0.5rem 1.5rem; border-radius: 2rem; font-size: 1.25rem; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }

        .status-in-progress { background: rgba(237, 20, 43, 0.2); color: #ff4d4d; border: 1px solid rgba(237, 20, 43, 0.5); }
        .status-ready { background: rgba(34, 197, 94, 0.2); color: #4ade80; border: 1px solid rgba(34, 197, 94, 0.5); }
        
        @keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Auto scroll */
        .scroller { overflow: hidden; flex: 1; position: relative; }
        .scroll-content { position: absolute; width: 100%; transition: top 1s linear; }

        header { padding: 1.5rem 2rem; background: rgba(0, 0, 0, 0.3); border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; height: 100px; box-sizing: border-box; }
        .logo-text { font-size: 2.5rem; font-weight: 900; font-style: italic; letter-spacing: -1px; }
        .logo-red { color: #ed142b; }
        .clock { font-size: 1.5rem; font-weight: 700; color: #94a3b8; }
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
        <!-- In Progress Column -->
        <div class="column">
            <div class="col-title">
                <span style="color:#ff4d4d;">IN PROGRESS</span>
                <span id="count-progress" style="background:#ff4d4d;color:white;padding:0.25rem 1rem;border-radius:1rem;font-size:1.5rem;">0</span>
            </div>
            <div class="scroller" id="scroll-progress"><div class="scroll-content" id="list-progress"></div></div>
        </div>

        <!-- Ready / Waiting Column -->
        <div class="column">
            <div class="col-title">
                <span style="color:#4ade80;">READY / WAITING</span>
                <span id="count-ready" style="background:#4ade80;color:#06162f;padding:0.25rem 1rem;border-radius:1rem;font-size:1.5rem;">0</span>
            </div>
            <div class="scroller" id="scroll-ready"><div class="scroll-content" id="list-ready"></div></div>
        </div>
    </div>

    <script>
        // Update Clock
        setInterval(() => {
            document.getElementById('clock').innerText = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }, 1000);

        let autoScrollers = {};

        function escapeBoardText(value) {
            const element = document.createElement('span');
            element.textContent = String(value ?? '');
            return element.innerHTML;
        }

        function startAutoScroll(id) {
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
                const res = await fetch('{{ route('live-board.data') }}', { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('Board data is unavailable.');
                const data = await res.json();
                
                let inProgressHtml = '';
                let readyHtml = '';
                let inProgressCount = 0;
                let readyCount = 0;

                Object.values(data.groups || {}).flat().forEach(card => {
                    // Combine similar statuses
                    let isReady = ['ready', 'pending_payment'].includes(card.database_status) || ['ready', 'pending_payment'].includes(card.status);
                    
                    const statusLabel = card.database_status || card.status.replaceAll('_', ' ');
                    
                    const html = `
                        <div class="bike-card">
                            <div>
                                <div class="bike-number">${escapeBoardText(card.bike_number)}</div>
                                <div class="bike-customer">${escapeBoardText(card.customer)}</div>
                            </div>
                            <div class="status-badge ${isReady ? 'status-ready' : 'status-in-progress'}">${escapeBoardText(statusLabel)}</div>
                        </div>
                    `;

                    if (isReady) {
                        readyHtml += html;
                        readyCount++;
                    } else {
                        inProgressHtml += html;
                        inProgressCount++;
                    }
                });

                document.getElementById('count-progress').innerText = inProgressCount;
                document.getElementById('count-ready').innerText = readyCount;
                
                const currentProgressHtml = document.getElementById('list-progress').innerHTML;
                const currentReadyHtml = document.getElementById('list-ready').innerHTML;

                if (currentProgressHtml !== inProgressHtml) {
                    document.getElementById('list-progress').innerHTML = inProgressHtml;
                    startAutoScroll('progress');
                }
                if (currentReadyHtml !== readyHtml) {
                    document.getElementById('list-ready').innerHTML = readyHtml;
                    startAutoScroll('ready');
                }

            } catch (err) {
                console.error("Board fetch error", err);
            }
        }

        fetchData();
        setInterval(fetchData, 10000); // 10s refresh
    </script>
</body>
</html>
