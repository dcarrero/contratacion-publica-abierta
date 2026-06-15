<x-layouts.app title="Comparar provincias — Contratación Abierta"
    metaDescription="Compara la contratación pública de dos provincias españolas lado a lado: gasto, importe por habitante, sectores y principales adjudicatarios.">

    <div class="max-w-3xl mx-auto" x-data="{ a: '', b: '' }">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">Comparar provincias</h1>
        <p class="text-gray-600 mb-8">
            Elige dos provincias para comparar su contratación pública lado a lado: gasto total,
            importe por habitante, % de contratos menores y principales adjudicatarios.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
            <select x-model="a" class="rounded-lg border-gray-300 text-sm px-3 py-2 focus:ring-primary focus:border-primary">
                <option value="">Provincia A…</option>
                @foreach($provincias as $p)
                <option value="{{ $p['slug'] }}">{{ $p['nombre'] }}</option>
                @endforeach
            </select>
            <select x-model="b" class="rounded-lg border-gray-300 text-sm px-3 py-2 focus:ring-primary focus:border-primary">
                <option value="">Provincia B…</option>
                @foreach($provincias as $p)
                <option value="{{ $p['slug'] }}">{{ $p['nombre'] }}</option>
                @endforeach
            </select>
        </div>

        <a x-bind:href="(a && b && a !== b) ? `/comparar/${a}/${b}` : '#'"
           x-bind:class="(a && b && a !== b) ? 'bg-primary text-white hover:bg-primary-dark' : 'bg-gray-200 text-gray-400 pointer-events-none'"
           class="inline-flex items-center px-5 py-2.5 rounded-lg font-medium transition">
            Comparar
        </a>
    </div>

</x-layouts.app>
