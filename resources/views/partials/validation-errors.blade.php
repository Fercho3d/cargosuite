@if ($errors->any())
    <div class="mb-4 rounded-lg border border-accent-700 bg-accent-700/15 px-4 py-3 text-sm text-brand">
        <ul class="list-inside list-disc space-y-1">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
