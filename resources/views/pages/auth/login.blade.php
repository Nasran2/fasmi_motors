<x-layouts::auth.workshop :title="__('Log in')">
    <div class="flex flex-col">
        <div class="text-center">
            <x-workshop-mark class="mx-auto size-20 shadow-lg ring-4 ring-slate-100 sm:size-24" />
            <h1 class="mt-5 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Welcome Back</h1>
            <p class="mt-2 text-sm text-slate-500 sm:text-base">Login to continue your workshop operations</p>
        </div>

        <x-auth-session-status class="mt-5 text-center" :status="session('status')" />

        @if($errors->any())
            <div class="mt-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" class="mt-7 space-y-5">
            @csrf
            <label class="group block">
                <span class="sr-only">Username or email</span>
                <span class="relative block">
                    <svg class="pointer-events-none absolute left-4 top-1/2 size-6 -translate-y-1/2 text-slate-500 transition group-focus-within:text-[#ef172d]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="4"/><path d="M4.5 21c.5-5 3-8 7.5-8s7 3 7.5 8"/></svg>
                    <input name="login" value="{{ old('login') }}" type="text" required autofocus autocomplete="username" placeholder="Username / Email" class="h-14 w-full rounded-xl border border-slate-300 bg-white !pl-14 !pr-4 text-base text-slate-900 outline-none transition placeholder:text-slate-500 focus:border-[#ef172d] focus:ring-4 focus:ring-red-100 sm:h-16">
                </span>
            </label>

            <label class="group block">
                <span class="sr-only">Password</span>
                <span class="relative block">
                    <svg class="pointer-events-none absolute left-4 top-1/2 size-6 -translate-y-1/2 text-slate-500 transition group-focus-within:text-[#ef172d]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></svg>
                    <input id="login-password" name="password" type="password" required autocomplete="current-password" placeholder="Password" class="h-14 w-full rounded-xl border border-slate-300 bg-white !pl-14 !pr-14 text-base text-slate-900 outline-none transition placeholder:text-slate-500 focus:border-[#ef172d] focus:ring-4 focus:ring-red-100 sm:h-16">
                    <button type="button" id="password-toggle" class="absolute right-3 top-1/2 grid size-10 -translate-y-1/2 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Show password">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </span>
            </label>

            <div class="flex items-center justify-between gap-4 text-sm">
                <label class="flex cursor-pointer items-center gap-2 text-slate-700"><input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-slate-300 text-[#ef172d] focus:ring-[#ef172d]"> Remember Me</label>
                @if(Route::has('password.request'))<a href="{{ route('password.request') }}" class="font-medium text-[#e51229] hover:underline" wire:navigate>Forgot Password?</a>@endif
            </div>

            <button type="submit" class="flex h-14 w-full items-center justify-center gap-2 rounded-xl bg-[#ed1028] px-5 text-base font-bold text-white shadow-lg shadow-red-200 transition hover:bg-[#cd0d21] focus:outline-none focus:ring-4 focus:ring-red-200 active:scale-[.99] sm:h-16 sm:text-lg" data-test="login-button">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                Sign In to System
            </button>
        </form>

        <div class="my-7 flex items-center gap-4 text-slate-300"><span class="h-px flex-1 bg-current"></span><svg class="size-5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 2 8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5l8-3Z"/><path d="m9 12 2 2 4-5"/></svg><span class="h-px flex-1 bg-current"></span></div>
        <p class="text-center text-xs leading-relaxed text-slate-500 sm:text-sm">Authorized staff only. All activities are monitored.</p>
        <p class="mt-5 text-center text-xs text-slate-500 sm:text-sm">Fasmir Motors Bike Repair Center</p>
    </div>

    <script>
        document.getElementById('password-toggle')?.addEventListener('click', function () {
            const input = document.getElementById('login-password');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    </script>
</x-layouts::auth.workshop>
