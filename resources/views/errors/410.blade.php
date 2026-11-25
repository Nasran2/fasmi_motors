<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link Unavailable · Fasmir Motors</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #0b1d36; color: #fff; font-family: Arial, "DejaVu Sans", sans-serif; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 20px; }
        .card { max-width: 480px; width: 100%; background: #fff; color: #1e293b; border-radius: 20px; padding: 36px 28px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.3); }
        .icon { width: 64px; height: 64px; margin: 0 auto 16px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; color: #dc2626; }
        h1 { margin: 0 0 10px; font-size: 22px; font-weight: 800; color: #0b1d36; }
        p { margin: 0 0 20px; font-size: 14px; line-height: 1.5; color: #64748b; }
        .btn { display: inline-block; background: #d91e2b; color: #fff; text-decoration: none; font-weight: 700; font-size: 13px; padding: 12px 24px; border-radius: 10px; transition: background 0.2s; }
        .btn:hover { background: #b91c1c; }
        .brand { margin-top: 24px; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">✕</div>
        <h1>Invoice Link Unavailable</h1>
        <p>{{ $exception->getMessage() ?: 'This invoice link has expired or has been disabled. Please contact the workshop to request an updated bill link.' }}</p>
        <a href="{{ url('/') }}" class="btn">Return to Fasmir Motors</a>
        <div class="brand">Fasmir Motors · Bike Repair Center</div>
    </div>
</body>
</html>
