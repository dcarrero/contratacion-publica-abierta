<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contrato;
use App\Models\Organismo;
use Illuminate\Http\Response;

class RssController extends Controller
{
    public function contratos(): Response
    {
        $contratos = Contrato::with(['organismo', 'adjudicatario'])
            ->whereNotNull('fecha_publicacion')
            ->orderByDesc('fecha_publicacion')
            ->limit(50)
            ->get();

        $xml = $this->buildRss(
            'Contratación Abierta — Últimos contratos',
            url('/contratos'),
            'Últimos contratos públicos de España',
            $contratos,
        );

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    public function organismo(string $nif): Response
    {
        $organismo = Organismo::where('nif', $nif)->firstOrFail();

        $contratos = Contrato::with(['organismo', 'adjudicatario'])
            ->where('organismo_id', $organismo->id)
            ->whereNotNull('fecha_publicacion')
            ->orderByDesc('fecha_publicacion')
            ->limit(50)
            ->get();

        $xml = $this->buildRss(
            "Contratación Abierta — {$organismo->nombre}",
            url("/organismos/{$organismo->nif}"),
            "Últimos contratos de {$organismo->nombre}",
            $contratos,
        );

        return response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }

    private function buildRss(string $title, string $link, string $description, $contratos): string
    {
        $items = '';
        foreach ($contratos as $contrato) {
            $itemTitle = e($contrato->objeto ?: 'Contrato '.$contrato->placsp_id);
            $itemLink = url("/contratos/{$contrato->placsp_id}");
            $pubDate = $contrato->fecha_publicacion?->toRfc2822String() ?? '';
            $adjNombre = $contrato->adjudicatario?->nombre ?? 'Sin adjudicatario';
            $importe = $contrato->importe_adjudicacion
                ? number_format((float) $contrato->importe_adjudicacion, 2, ',', '.').' EUR'
                : 'Sin importe';
            $orgNombre = $contrato->organismo?->nombre ?? 'Sin organismo';

            $itemDesc = e("Organismo: {$orgNombre} | Adjudicatario: {$adjNombre} | Importe: {$importe}");

            $items .= <<<XML
        <item>
            <title>{$itemTitle}</title>
            <link>{$itemLink}</link>
            <description>{$itemDesc}</description>
            <pubDate>{$pubDate}</pubDate>
            <guid isPermaLink="true">{$itemLink}</guid>
        </item>
XML;
        }

        $titleEsc = e($title);
        $descEsc = e($description);
        $buildDate = now()->toRfc2822String();

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>{$titleEsc}</title>
        <link>{$link}</link>
        <description>{$descEsc}</description>
        <language>es</language>
        <lastBuildDate>{$buildDate}</lastBuildDate>
        <atom:link href="{$link}" rel="self" type="application/rss+xml"/>
{$items}
    </channel>
</rss>
XML;
    }
}
