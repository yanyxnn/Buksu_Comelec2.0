{{--
    x-admin.header — top bar for administrator pages: menu button (mobile), COMELEC branding, page title,
    notifications, administrator identity and the existing logout action (POST route('logout')).
    Reads only the signed-in admin's own unread database notifications; it writes nothing.
--}}
@props(['title'])
@php
    $admin = auth('admin')->user();
    $unreadCount = $admin ? $admin->unreadNotifications()->count() : 0;
    $recent = $admin ? $admin->unreadNotifications()->latest()->limit(5)->get() : collect();
    $name = $admin?->display_name;
    $initials = $name ? collect(preg_split('/\s+/', trim($name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('') : '';
@endphp

<header class="sticky top-0 z-20 border-b border-line bg-canvas/80 backdrop-blur-xl">
    <div class="flex min-h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
        <x-ui.icon-button class="lg:hidden" icon="bars-3" :label="__('Open navigation')" x-on:click="nav = true" />

        {{-- Branding + page title (the sidebar carries the full wordmark on desktop). --}}
        <div class="min-w-0">
            <a href="{{ route('admin.home') }}" class="ui-eyebrow block truncate rounded-ui hover:text-ink focus-ui">{{ __('BUKSU COMELEC') }}<span class="hidden sm:inline"> · {{ __('Administration') }}</span></a>
            <h1 class="truncate font-heading text-lg leading-tight font-semibold sm:text-xl">{{ $title }}</h1>
        </div>

        <div class="ml-auto flex items-center gap-2 sm:gap-3">
            {{-- Notifications --}}
            <div class="relative" x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false">
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true" aria-controls="admin-notifications"
                        class="relative inline-flex size-11 items-center justify-center rounded-ui border border-line bg-surface text-muted transition-colors hover:border-line-strong hover:text-ink focus-ui"
                        aria-label="{{ $unreadCount > 0 ? __(':count unread notifications', ['count' => $unreadCount]) : __('Notifications') }}">
                    <flux:icon name="bell" class="size-5" />
                    @if ($unreadCount > 0)
                        <span class="absolute -top-1 -right-1 inline-flex min-w-5 items-center justify-center rounded-full bg-accent px-1 text-[0.7rem] leading-5 font-bold text-accent-foreground tabular-nums" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
                    @endif
                </button>

                <div id="admin-notifications" x-cloak x-show="open" x-transition.origin.top.right
                     class="absolute right-0 z-30 mt-2 w-[min(22rem,calc(100vw-2rem))] rounded-ui border border-line bg-surface shadow-ui">
                    <div class="border-b border-line px-4 py-3">
                        <p class="ui-section-title">{{ __('Notifications') }}</p>
                    </div>
                    <ul class="max-h-80 divide-y divide-line overflow-y-auto text-sm">
                        @forelse ($recent as $notification)
                            <li class="px-4 py-3">
                                <p>{{ __('Change request #:id (:type) proposed by :name', [
                                    'id' => $notification->data['change_request_id'] ?? '?',
                                    'type' => $notification->data['action_type'] ?? '?',
                                    'name' => $notification->data['requested_by_name'] ?? '?',
                                ]) }}</p>
                                <p class="mt-1 text-xs text-subtle">{{ $notification->created_at?->diffForHumans() }}</p>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-muted">{{ __('No new notifications.') }}</li>
                        @endforelse
                    </ul>
                    <div class="border-t border-line px-4 py-3 text-sm">
                        <a href="{{ route('admin.approvals') }}" class="font-semibold text-brass-text underline-offset-4 hover:underline focus-ui">{{ __('Open approvals') }}</a>
                    </div>
                </div>
            </div>

            {{-- Administrator identity + logout --}}
            @if ($name)
                <div class="flex items-center gap-3 border-l border-line pl-2 sm:pl-3">
                    <span class="hidden size-9 shrink-0 items-center justify-center rounded-full bg-surface-subtle text-sm font-semibold text-brass-text ring-1 ring-line select-none sm:inline-flex" aria-hidden="true">{{ $initials }}</span>
                    <div class="hidden min-w-0 leading-tight sm:block">
                        <p class="max-w-44 truncate text-sm font-semibold">{{ $name }}</p>
                        <p class="text-xs text-muted">{{ __('Administrator') }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-ui.button type="submit" size="sm" icon="arrow-right-start-on-rectangle">{{ __('Log out') }}</x-ui.button>
                    </form>
                </div>
            @endif
        </div>
    </div>
</header>
