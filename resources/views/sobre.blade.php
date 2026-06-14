<x-layouts.app title="Sobre este proyecto — Contratación Abierta">

    <div class="max-w-3xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-900 mb-6">Sobre este proyecto</h1>

        <div class="bg-white rounded-lg shadow p-6 sm:p-8 space-y-6 text-gray-700 leading-relaxed">

            <section>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Qué es</h2>
                <p>
                    <strong>Contratación Abierta</strong> es un portal de transparencia ciudadana que recopila,
                    normaliza y presenta los contratos públicos de toda España: administración estatal,
                    comunidades autónomas, diputaciones, ayuntamientos y sector público.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">De dónde vienen los datos</h2>
                <p>
                    Este portal agrega datos de <strong>{{ number_format(\App\Models\Contrato::count(), 0, ',', '.') }} contratos públicos</strong>
                    procedentes de múltiples fuentes oficiales de administraciones públicas españolas.
                    Los adjudicatarios se normalizan por NIF/CIF para evitar duplicados provocados
                    por variantes en los nombres (mayúsculas, abreviaturas, tildes, etc.).
                </p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Fuentes de datos</h2>

                {{-- PLACSP --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Plataforma de Contratación del Sector Público (PLACSP)
                    </h3>
                    <p class="text-sm mt-1">
                        Portal oficial del <strong>Gobierno de España</strong> (Ministerio de Hacienda)
                        para la publicación de licitaciones y contratos de todas las administraciones públicas.
                        Fuente principal de datos. Se importan 4 feeds de datos abiertos.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://contrataciondelestado.es" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">contrataciondelestado.es</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Datos abiertos:</dt>
                            <dd class="inline">
                                <a href="https://www.hacienda.gob.es/es-ES/GobiernoAbierto/Datos%20Abiertos/Paginas/Contratacion.aspx" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">hacienda.gob.es (datos abiertos)</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">Atom XML (CODICE 2.07)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Cobertura:</dt>
                            <dd class="inline">Toda España, desde 2012</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Actualización:</dt>
                            <dd class="inline">Diaria (sincronización incremental)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Incluye:</dt>
                            <dd class="inline">Licitaciones, contratos menores, plataformas agregadas y encargos a medios propios</dd>
                        </div>
                    </dl>
                </div>

                {{-- BQuant --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        BQuant Finance — Dataset histórico
                    </h3>
                    <p class="text-sm mt-1">
                        Dataset de <strong>8,69 millones de registros</strong> de licitaciones públicas españolas,
                        recopilado y estructurado por BQuant Finance. Utilizado para la carga inicial de datos históricos
                        y para enriquecer campos como el importe con IVA.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://bquant.io" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">bquant.io</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Dataset:</dt>
                            <dd class="inline">
                                <a href="https://huggingface.co/datasets/bquantfinance/licitaciones-espana" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">licitaciones-espana (Hugging Face)</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">Parquet (965 MB)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Licencia:</dt>
                            <dd class="inline">Datos abiertos</dd>
                        </div>
                    </dl>
                </div>

                {{-- Catalunya --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Generalitat de Catalunya — Contractació Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos de la <strong>Generalitat de Catalunya</strong> con más de
                        1,69 millones de registros de contratación pública catalana. API Socrata con acceso libre.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://analisi.transparenciacatalunya.cat" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">analisi.transparenciacatalunya.cat</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Dataset:</dt>
                            <dd class="inline">
                                <a href="https://analisi.transparenciacatalunya.cat/Sector-P-blic/Contractaci-p-blica-de-la-Generalitat-de-Cataluny/ybgg-dgi6" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">Contractació pública de la Generalitat</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">API Socrata (JSON)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Registros:</dt>
                            <dd class="inline">~1.692.000</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Licencia:</dt>
                            <dd class="inline">Datos abiertos Generalitat</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Actualización:</dt>
                            <dd class="inline">Semanal</dd>
                        </div>
                    </dl>
                </div>

                {{-- Andalucía --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Junta de Andalucía — Contratos Menores
                    </h3>
                    <p class="text-sm mt-1">
                        Datos abiertos de la <strong>Junta de Andalucía</strong> sobre contratos menores
                        de la administración autonómica. Ficheros CSV anuales desde 2018.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://www.juntadeandalucia.es/datosabiertos/portal/" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">juntadeandalucia.es/datosabiertos</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Dataset:</dt>
                            <dd class="inline">
                                <a href="https://www.juntadeandalucia.es/datosabiertos/portal/dataset/contratos-menores" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">Contratos menores</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">CSV (delimitado por pipe)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Cobertura:</dt>
                            <dd class="inline">2018–2024</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Licencia:</dt>
                            <dd class="inline">CC BY 4.0</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Actualización:</dt>
                            <dd class="inline">Trimestral (CSV anuales)</dd>
                        </div>
                    </dl>
                </div>

                {{-- Castilla y León --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Junta de Castilla y León — Contratación Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos de la <strong>Junta de Castilla y León</strong> con contratos
                        menores y ordinarios de la administración autonómica.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://datosabiertos.jcyl.es" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">datosabiertos.jcyl.es</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Datasets:</dt>
                            <dd class="inline">
                                <a href="https://analisis.datosabiertos.jcyl.es/explore/dataset/contratos-menores-702/information/" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">Contratos menores</a>
                                ·
                                <a href="https://analisis.datosabiertos.jcyl.es/explore/dataset/contratacion-publica-de-la-administracion-de-castilla-y-leon/information/" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">Contratos ordinarios</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">CSV (API Opendatasoft)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Cobertura:</dt>
                            <dd class="inline">Desde 2019</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Licencia:</dt>
                            <dd class="inline">Datos abiertos JCyL</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Actualización:</dt>
                            <dd class="inline">Trimestral</dd>
                        </div>
                    </dl>
                </div>

                {{-- País Vasco --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Gobierno Vasco — Contratación Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos del <strong>Gobierno Vasco</strong> con más de
                        500.000 registros de contratación pública de la administración autonómica vasca.
                        Ficheros JSON anuales con acceso libre.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://opendata.euskadi.eus" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">opendata.euskadi.eus</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Dataset:</dt>
                            <dd class="inline">
                                <a href="https://opendata.euskadi.eus/catalogo/-/contrataciones-administrativas-del-2025/" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">Contrataciones administrativas</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">JSON anual (catálogo abierto)</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Cobertura:</dt>
                            <dd class="inline">2018–2026</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Licencia:</dt>
                            <dd class="inline">Datos abiertos Gobierno Vasco</dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Actualización:</dt>
                            <dd class="inline">Semanal (JSON anuales)</dd>
                        </div>
                    </dl>
                </div>

                {{-- Asturias --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Principado de Asturias — Contratación Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos del <strong>Principado de Asturias</strong> con más de
                        375.000 registros de contratación pública. Calidad de datos excelente (91 campos).
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://www.asturias.es/opendata" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">asturias.es/opendata</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">CSV anuales (2019–2024)</dd>
                        </div>
                    </dl>
                </div>

                {{-- Valencia --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Generalitat Valenciana — Contractació Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos de la <strong>Generalitat Valenciana</strong> con más de
                        200.000 registros. Calidad excelente: 70 campos, NIF completo, importes sin/con IVA y CPV.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://dadesobertes.gva.es" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">dadesobertes.gva.es</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">CSV anuales (2018–2025)</dd>
                        </div>
                    </dl>
                </div>

                {{-- Canarias --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Gobierno de Canarias — Contratación Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos del <strong>Gobierno de Canarias</strong> con más de
                        222.000 registros de contratación pública desde 2020.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://datos.canarias.es" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">datos.canarias.es</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">CSV</dd>
                        </div>
                    </dl>
                </div>

                {{-- Aragón --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Gobierno de Aragón — Contratación Pública
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de datos abiertos del <strong>Gobierno de Aragón</strong> con más de
                        152.000 registros de contratación pública desde 2009.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://opendata.aragon.es" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">opendata.aragon.es</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">CSV dinámico (2009–2025)</dd>
                        </div>
                    </dl>
                </div>

                {{-- Madrid --}}
                <div class="border border-gray-200 rounded-lg p-4 mb-4">
                    <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                        <span class="inline-block w-2 h-2 bg-green-500 rounded-full"></span>
                        Comunidad de Madrid — Contratos Públicos
                    </h3>
                    <p class="text-sm mt-1">
                        Portal de contratos públicos de la <strong>Comunidad de Madrid</strong>.
                        Feed Atom en formato CODICE, idéntico al de PLACSP.
                    </p>
                    <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <div>
                            <dt class="text-gray-500 inline">Portal:</dt>
                            <dd class="inline">
                                <a href="https://contratos-publicos.comunidad.madrid" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">contratos-publicos.comunidad.madrid</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-500 inline">Formato:</dt>
                            <dd class="inline">Atom XML (CODICE)</dd>
                        </div>
                    </dl>
                </div>

                <p class="text-sm text-gray-500 mt-2">
                    Todas las fuentes son portales oficiales de administraciones públicas españolas.
                    Los datos se descargan, normalizan y cruzan automáticamente para ofrecer una visión unificada
                    de la contratación pública en España.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Inspiraciones</h2>
                <p>
                    Este proyecto se inspira en iniciativas similares de transparencia en contratación pública:
                </p>
                <ul class="mt-2 list-disc list-inside space-y-1 text-sm">
                    <li>
                        <a href="https://contratacionabierta.es" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">contratacionabierta.es</a>
                        — portal nacional de contratación abierta (naroh)
                    </li>
                    <li>
                        <a href="https://contractes.cat" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">contractes.cat</a>
                        — portal de contratos públicos de Cataluña (Ciència de Dades)
                    </li>
                </ul>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Aviso importante</h2>
                <p>
                    Este <strong>NO es un sitio web oficial</strong> de ninguna administración pública.
                    Es un proyecto independiente de transparencia ciudadana sin ánimo de lucro.
                </p>
                <p class="mt-2">
                    Los datos se ofrecen tal cual se publican en PLACSP. Puede haber errores derivados
                    del procesamiento automático. Ante cualquier duda, consulte la fuente oficial.
                </p>
            </section>

            <section>
                <h2 class="text-lg font-semibold text-gray-900 mb-2">Código fuente y catálogo de fuentes</h2>
                <p>
                    El catálogo de fuentes de datos de contratación pública, con enlaces directos a 40+ archivos
                    ZIP descargables y ejemplos de código, está disponible en
                    <a href="https://contratacionabierta.com" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">ContratacionAbierta.com</a>
                    y su código fuente es abierto en
                    <a href="https://github.com/dcarrero/ContratacionAbierta" target="_blank" rel="noopener" class="text-primary hover:text-primary-dark underline">GitHub</a>.
                </p>
            </section>

        </div>
    </div>

</x-layouts.app>
