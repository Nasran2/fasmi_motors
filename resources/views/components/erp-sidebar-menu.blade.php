@php
    $groups = config('workshop-navigation.groups');
    $directLinks = config('workshop-navigation.direct');
    $currentSection = request()->route('section');
    $currentPage = request()->route('page');

    if (session('shop_mode', 'workshop') === 'helmet') {
        $allowedGroups = ['dashboard', 'customers-bikes', 'products-stock', 'sales-billing', 'accounts', 'expenses-drawings', 'reports', 'settings'];
        $groups = collect($groups)->filter(fn($g) => in_array($g['slug'], $allowedGroups))->values()->toArray();
        foreach ($groups as &$group) {
            if ($group['slug'] === 'dashboard') {
                $group['children'] = collect($group['children'])->filter(fn($c) => in_array($c['slug'], ['overview']))->values()->toArray();
            }
            elseif ($group['slug'] === 'customers-bikes') {
                $group['children'] = collect($group['children'])->filter(fn($c) => in_array($c['slug'], ['customer-list', 'add-customer', 'customer-due']))->values()->toArray();
                $group['title'] = 'Customers';
            }
            elseif ($group['slug'] === 'products-stock') {
                $group['children'] = collect($group['children'])->filter(fn($c) => in_array($c['slug'], ['product-list', 'add-product', 'suppliers', 'categories', 'brands', 'units', 'stores', 'store-stock', 'stock-transfer', 'stock-adjustment', 'low-stock', 'damage-write-off']))->values()->toArray();
            }
            elseif ($group['slug'] === 'sales-billing') {
                $group['children'] = collect($group['children'])->filter(fn($c) => in_array($c['slug'], ['parts-sales', 'invoice-list', 'payments', 'due-bills', 'customer-payments']))->values()->toArray();
                $group['title'] = 'POS & Sales';
            }
            elseif ($group['slug'] === 'accounts') {
                $group['children'] = collect($group['children'])->filter(fn($c) => $c['slug'] !== 'spare-parts-ledger')->values()->toArray();
            }
            elseif ($group['slug'] === 'reports') {
                $group['children'] = collect($group['children'])->filter(fn($c) => in_array($c['slug'], ['sales-report', 'profit-loss', 'stock-report']))->values()->toArray();
            }
            elseif ($group['slug'] === 'settings') {
                $group['children'] = collect($group['children'])->filter(fn($c) => in_array($c['slug'], ['general-settings', 'business-profile', 'branch-store-settings', 'stock-settings']))->values()->toArray();
            }
        }
    }
@endphp

<div class="relative flex min-h-0 flex-1 flex-col" data-erp-menu>
    <div class="px-1 pb-3 pt-4">
        <label for="sidebar-menu-search" class="sr-only">Search menu</label>
        <div class="relative">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
            <input id="sidebar-menu-search" data-menu-search type="search" placeholder="Search menu..." autocomplete="off" class="h-10 w-full rounded-xl border border-white/10 bg-white/[.055] pl-9 pr-3 text-xs font-medium text-white outline-none transition placeholder:text-slate-500 focus:border-red-500/50 focus:bg-white/[.08] focus:ring-4 focus:ring-red-500/5">
        </div>
    </div>

    <div class="mb-2 flex items-center justify-between px-3"><span class="text-[9px] font-bold uppercase tracking-[.2em] text-slate-500">Main Menu</span><span class="rounded-md bg-white/5 px-1.5 py-0.5 text-[8px] font-bold text-slate-500">{{ count($groups) }}</span></div>

    <nav class="erp-menu-scroll min-h-0 flex-1 space-y-1 overflow-y-auto pr-1" aria-label="Workshop navigation">
        @foreach($groups as $group)
            @php
                $groupHasDirectActive = collect($group['children'])->contains(function ($child) use ($directLinks, $group) {
                    $direct = $directLinks[$group['slug'].'.'.$child['slug']] ?? null;
                    return $direct
                        && collect($direct['active'] ?? [$direct['route']])->contains(fn ($pattern) => request()->routeIs($pattern))
                        && collect($direct['query'] ?? [])->every(fn ($value, $key) => request()->query($key, $value) === $value);
                });
                $groupActive = $currentSection === $group['slug'] || $groupHasDirectActive;
            @endphp
            <section class="erp-menu-group" data-menu-group data-title="{{ Str::lower($group['title']) }}" data-open="{{ $groupActive ? 'true' : 'false' }}">
                <button type="button" data-menu-toggle aria-expanded="{{ $groupActive ? 'true' : 'false' }}" class="erp-menu-button {{ $groupActive ? 'erp-menu-button-active' : '' }}">
                    <span class="erp-menu-icon"><x-sidebar-menu-icon :name="$group['icon']" class="size-[18px]"/></span>
                    <span class="min-w-0 flex-1 truncate text-left text-[12px] font-bold">{{ $group['title'] }}</span>
                    <svg class="erp-menu-chevron size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 7 5 5-5 5"/></svg>
                </button>

                <div class="erp-submenu" data-menu-panel>
                    <div class="min-h-0 overflow-hidden">
                        <div class="relative pb-2 pt-1">
                            <!-- Vertical Line -->
                            <div class="absolute bottom-2 left-[26px] top-1 w-px bg-white/10"></div>
                            
                            <div class="flex flex-col space-y-[2px] px-2">
                                @foreach($group['children'] as $child)
                                    @php
                                        $key = $group['slug'].'.'.$child['slug'];
                                        $direct = $directLinks[$key] ?? null;
                                        $url = $direct
                                            ? route($direct['route'], $direct['parameters'] ?? [])
                                            : route('workspace.page', ['section' => $group['slug'], 'page' => $child['slug']]);
                                        $childActive = $direct
                                            ? collect($direct['active'] ?? [$direct['route']])->contains(fn ($pattern) => request()->routeIs($pattern))
                                                && collect($direct['query'] ?? [])->every(fn ($value, $key) => request()->query($key, $value) === $value)
                                            : $currentSection === $group['slug'] && $currentPage === $child['slug'];
                                    @endphp
                                    <a href="{{ $url }}" data-menu-child data-search="{{ Str::lower($group['title'].' '.$child['title']) }}" class="erp-submenu-link {{ $childActive ? 'erp-submenu-link-active' : '' }}">
                                        <span class="erp-submenu-dot"></span><span class="truncate erp-submenu-text">{{ $child['title'] }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        @endforeach
        <div data-menu-empty class="hidden px-4 py-10 text-center"><span class="mx-auto grid size-10 place-items-center rounded-xl bg-white/5 text-slate-500"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M8.5 11h5"/></svg></span><p class="mt-3 text-xs font-bold text-slate-400">No menu item found</p></div>
    </nav>
</div>

<script>
    (() => {
        const root = document.querySelector('[data-erp-menu]');
        if (!root || root.dataset.ready === 'true') return;
        root.dataset.ready = 'true';
        const search = root.querySelector('[data-menu-search]');
        const groups = [...root.querySelectorAll('[data-menu-group]')];
        const empty = root.querySelector('[data-menu-empty]');
        const scroller = root.querySelector('.erp-menu-scroll');

        const setOpen = (group, open) => {
            group.dataset.open = open ? 'true' : 'false';
            group.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        groups.forEach(group => group.querySelector('[data-menu-toggle]').addEventListener('click', () => {
            const willOpen = group.dataset.open !== 'true';
            groups.forEach(other => setOpen(other, false));
            setOpen(group, willOpen);
        }));

        search.addEventListener('input', () => {
            const term = search.value.trim().toLowerCase();
            let visibleGroups = 0;
            groups.forEach(group => {
                const titleMatches = group.dataset.title.includes(term);
                const children = [...group.querySelectorAll('[data-menu-child]')];
                let childMatches = 0;
                children.forEach(child => {
                    const matches = !term || titleMatches || child.dataset.search.includes(term);
                    child.classList.toggle('hidden', !matches);
                    if (matches) childMatches++;
                });
                const visible = !term || titleMatches || childMatches > 0;
                group.classList.toggle('hidden', !visible);
                if (visible) visibleGroups++;
                if (term && visible) setOpen(group, true);
                if (!term) setOpen(group, group.querySelector('.erp-menu-button-active') !== null);
            });
            empty.classList.toggle('hidden', visibleGroups !== 0);
        });

        requestAnimationFrame(() => {
            const activeTarget = root.querySelector('.erp-submenu-link-active') || root.querySelector('.erp-menu-button-active');
            if (!activeTarget) return;
            const scrollBounds = scroller.getBoundingClientRect();
            const targetBounds = activeTarget.getBoundingClientRect();
            if (targetBounds.top < scrollBounds.top || targetBounds.bottom > scrollBounds.bottom) {
                scroller.scrollTop += targetBounds.top - scrollBounds.top - 64;
            }
        });
    })();
</script>
