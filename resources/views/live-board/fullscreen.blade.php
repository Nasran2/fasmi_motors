<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body class="min-h-screen bg-slate-100 text-slate-950">
    @include('live-board.board', ['fullscreen' => true])
</body>
</html>
