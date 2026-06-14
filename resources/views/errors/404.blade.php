<x-layouts.app title="Página no encontrada — Contratación Abierta">

<div class="max-w-2xl mx-auto py-20 text-center">
    <h1 class="text-6xl font-bold text-gray-300 mb-4">404</h1>
    <h2 class="text-2xl font-semibold text-gray-700 mb-4">Página no encontrada</h2>
    <p class="text-gray-500 mb-8">
        La página que buscas no existe o ha sido movida.
    </p>
    <div class="flex flex-wrap justify-center gap-4">
        <a href="{{ url('/') }}" class="inline-flex items-center px-5 py-2.5 bg-primary text-white rounded-lg hover:bg-primary-dark transition font-medium text-sm">
            Ir al inicio
        </a>
        <a href="{{ route('contratos.index') }}" class="inline-flex items-center px-5 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition font-medium text-sm">
            Buscar contratos
        </a>
    </div>
</div>

</x-layouts.app>
