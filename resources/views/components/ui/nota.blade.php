{{-- Nivel 3: metadatos, salvedades metodológicas y pies de nota. Sin caja: son
     aclaraciones, no contenido que haya que mirar. gray-500 (y no gray-400) para
     mantener el contraste AA sobre el fondo de la página. --}}
<p {{ $attributes->class('text-xs leading-relaxed text-gray-500') }}>{{ $slot }}</p>
