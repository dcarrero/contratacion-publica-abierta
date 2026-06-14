<x-layouts.app :title="$titulo . ' — Contratación Abierta'">

    <div class="max-w-lg mx-auto py-12">
        <div class="bg-white rounded-lg shadow p-8 text-center">
            @if($tipo === 'success')
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-100 flex items-center justify-center">
                    <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
            @elseif($tipo === 'error')
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 flex items-center justify-center">
                    <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
            @else
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            @endif

            <h1 class="text-xl font-bold text-gray-900 mb-2">{{ $titulo }}</h1>
            <p class="text-gray-600">{{ $mensaje }}</p>

            <div class="mt-8">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center px-4 py-2 bg-primary text-white rounded-lg hover:bg-primary-dark transition text-sm font-medium">
                    Volver al inicio
                </a>
            </div>
        </div>
    </div>

</x-layouts.app>
