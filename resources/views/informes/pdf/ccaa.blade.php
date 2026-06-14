@extends('informes.pdf.layout')

@section('title', 'Informe: ' . ($comunidad->nombre ?? 'CCAA'))

@section('content')
@if($data)
{{-- KPIs --}}
<table class="kpi-grid">
    <tr>
        <td>
            <div class="kpi-value">{{ number_format($data['kpis']['total_contratos'], 0, ',', '.') }}</div>
            <div class="kpi-label">Contratos</div>
        </td>
        <td>
            <div class="kpi-value">{{ formatImporteCorto($data['kpis']['total_importe']) }}</div>
            <div class="kpi-label">Importe total</div>
        </td>
        <td>
            <div class="kpi-value">{{ $data['kpis']['pct_menores'] }}%</div>
            <div class="kpi-label">% Menores</div>
        </td>
        <td>
            <div class="kpi-value">{{ formatImporteCorto($data['kpis']['importe_medio']) }}</div>
            <div class="kpi-label">Importe medio</div>
        </td>
    </tr>
    <tr>
        <td>
            <div class="kpi-value">{{ number_format($data['kpis']['total_organismos'], 0, ',', '.') }}</div>
            <div class="kpi-label">Organismos</div>
        </td>
        <td>
            <div class="kpi-value">{{ number_format($data['kpis']['total_adjudicatarios'], 0, ',', '.') }}</div>
            <div class="kpi-label">Adjudicatarios</div>
        </td>
        <td>
            <div class="kpi-value">{{ !empty($data['kpis']['poblacion']) ? number_format($data['kpis']['poblacion'], 0, ',', '.') : '—' }}</div>
            <div class="kpi-label">Población</div>
        </td>
        <td>
            <div class="kpi-value">{{ !empty($data['kpis']['gasto_per_capita']) ? formatImporte($data['kpis']['gasto_per_capita']) . '/hab' : '—' }}</div>
            <div class="kpi-label">Per cápita</div>
        </td>
    </tr>
</table>

{{-- Evolución anual --}}
@if(!empty($data['evolucion_anual']))
<h2>Evolución anual</h2>
<table class="data">
    <thead>
        <tr>
            <th>Año</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach(array_slice($data['evolucion_anual'], -10) as $row)
        <tr>
            <td>{{ $row['year'] }}</td>
            <td class="text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
            <td class="text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Distribución por tipo --}}
@if(!empty($data['distribucion_tipo']))
<h2>Distribución por tipo de contrato</h2>
<table class="data">
    <thead>
        <tr>
            <th>Tipo</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['distribucion_tipo'] as $row)
        <tr>
            <td>{{ $row['label'] }}</td>
            <td class="text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
            <td class="text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Top CPV --}}
@if(!empty($data['top_cpv']))
<h2>Principales sectores (CPV)</h2>
<table class="data">
    <thead>
        <tr>
            <th>CPV</th>
            <th>Descripción</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach(array_slice($data['top_cpv'], 0, 10) as $row)
        <tr>
            <td>{{ $row['cpv2'] }}</td>
            <td>{{ $row['descripcion'] }}</td>
            <td class="text-right">{{ number_format($row['num_contratos'], 0, ',', '.') }}</td>
            <td class="text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Top adjudicatarios --}}
@if(!empty($data['top_adjudicatarios']))
<div class="page-break"></div>
<h2>Principales adjudicatarios</h2>
<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>Empresa</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach(array_slice($data['top_adjudicatarios'], 0, 20) as $i => $row)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $row['nombre'] }} <span class="text-muted">{{ $row['nif'] }}</span></td>
            <td class="text-right">{{ number_format($row['total_contratos'], 0, ',', '.') }}</td>
            <td class="text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Top organismos --}}
@if(!empty($data['top_organismos']))
<h2>Principales organismos contratantes</h2>
<table class="data">
    <thead>
        <tr>
            <th>#</th>
            <th>Organismo</th>
            <th class="text-right">Contratos</th>
            <th class="text-right">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach(array_slice($data['top_organismos'], 0, 20) as $i => $row)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $row['nombre'] }}</td>
            <td class="text-right">{{ number_format($row['total_contratos'], 0, ',', '.') }}</td>
            <td class="text-right">{{ formatImporteCorto($row['total_importe']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- Anomalías --}}
@if(!empty($data['anomalias_resumen']) && $data['anomalias_resumen']['total'] > 0)
<h2>Anomalías detectadas</h2>
<table class="kpi-grid">
    <tr>
        <td><div class="kpi-value">{{ $data['anomalias_resumen']['total'] }}</div><div class="kpi-label">Total</div></td>
        <td><div class="kpi-value">{{ $data['anomalias_resumen']['fraccionamiento'] }}</div><div class="kpi-label">Fraccionamiento</div></td>
        <td><div class="kpi-value">{{ $data['anomalias_resumen']['concentracion'] }}</div><div class="kpi-label">Concentración</div></td>
        <td><div class="kpi-value">{{ $data['anomalias_resumen']['pico_temporal'] }}</div><div class="kpi-label">Pico temporal</div></td>
    </tr>
</table>
@endif

@else
<p>No hay datos disponibles para esta comunidad autónoma.</p>
@endif
@endsection
