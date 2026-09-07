{{-- Carga Chart.js una sola vez por página, la use quien la use. --}}
@once
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
@endpush
@endonce
