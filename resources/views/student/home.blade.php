<x-layouts.portal :title="__('Student')">
    <h1 class="text-xl font-semibold">{{ __('Welcome') }}, {{ auth('student')->user()->first_name }}</h1>
    <p class="mt-2 text-sm text-neutral-500">{{ __('Voting is not available yet.') }}</p>
</x-layouts.portal>
