<span class="hidden" data-live-metrics data-endpoint="{{ $endpoint }}" data-interval="{{ $interval ?? 3000 }}" aria-hidden="true"></span>

@once
    @push('scripts')
        <script src="{{ asset('assets/js/live-metrics.js') }}"></script>
    @endpush
@endonce
