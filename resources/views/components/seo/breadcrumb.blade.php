{{-- Datos estructurados BreadcrumbList (schema.org) para migas en los resultados de búsqueda. --}}
@props(['items' => []])
@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => collect($items)->values()->map(fn ($it, $i) => array_filter([
        '@type' => 'ListItem',
        'position' => $i + 1,
        'name' => $it['name'],
        'item' => $it['url'] ?? null,
    ], fn ($v) => $v !== null))->all(),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush
