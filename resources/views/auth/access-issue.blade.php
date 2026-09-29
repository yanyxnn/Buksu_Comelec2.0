<x-layouts.auth>
    <div class="flex flex-col gap-6">
        {{-- Identical for every post-Google denial: never says why. --}}
        <div class="text-center">
            <h1 class="text-xl font-semibold">{{ __('Student Access Issue') }}</h1>
            <p class="mt-2 text-sm">{{ __('We could not find your student record in the current university data.') }}</p>
            <p class="mt-1 text-sm text-neutral-500">{{ __('Please make sure you are using your institutional Google account.') }}</p>
        </div>

        {{-- Only a verified institutional Google account can submit a report. --}}
        @if ($canReport)
            <form method="POST" action="{{ route('access-issue.store') }}" class="flex flex-col gap-4">
                @csrf

                <p class="text-sm">{{ __('Institutional Google account:') }} <strong>{{ $email }}</strong></p>

                <label class="flex flex-col gap-1 text-sm">
                    {{ __('Problem type') }}
                    <select name="problem_type" required class="rounded-md border px-3 py-2">
                        <option value="">{{ __('Select a problem type') }}</option>
                        @foreach ($problemTypes as $key => $label)
                            <option value="{{ $key }}" @selected(old('problem_type') === $key)>{{ __($label) }}</option>
                        @endforeach
                    </select>
                </label>
                @error('problem_type') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="flex flex-col gap-1 text-sm">
                    {{ __('Student ID (optional)') }}
                    <input type="text" name="student_id" value="{{ old('student_id') }}" maxlength="50" class="rounded-md border px-3 py-2">
                </label>
                @error('student_id') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror

                <label class="flex flex-col gap-1 text-sm">
                    {{ __('Description') }}
                    <textarea name="description" rows="4" maxlength="1000" required class="rounded-md border px-3 py-2">{{ old('description') }}</textarea>
                </label>
                @error('description') <p role="alert" class="text-sm text-red-600">{{ $message }}</p> @enderror

                <button type="submit" class="rounded-md border px-4 py-2 text-sm font-medium">{{ __('Report Access Issue') }}</button>
            </form>
        @endif

        <a href="{{ route('auth.google.redirect') }}" class="text-center text-sm underline">{{ __('Use a different Google account') }}</a>
    </div>
</x-layouts.auth>
