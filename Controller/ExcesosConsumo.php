<?php

namespace FacturaScripts\Plugins\ExcesosConsumo\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\ExcesosConsumo\Model\ExcesosConsumoConfig;

class ExcesosConsumo extends Controller
{
    /** @var ExcesosConsumoConfig */
    public $config;

    /** @var array Datos procesados para previsualización */
    public $preview = [];

    /** @var array Resumen global */
    public $resumen = [];

    /** @var string Mensaje de estado */
    public $mensaje = '';

    /** @var string Tipo de mensaje (success, warning, danger) */
    public $tipoMensaje = '';

    /** @var array Documentos creados */
    public $documentosCreados = [];

    /** @var string Estado actual (upload, preview, done) */
    public $estado = 'upload';

    /** @var array Series disponibles */
    public $series = [];

    /** @var array Almacenes disponibles */
    public $almacenes = [];

    /** @var array Formas de pago disponibles */
    public $formasPago = [];

    /** @var bool Si hay un fichero de clientes guardado en el servidor */
    public $clientesCsvGuardado = false;

    /** @var string Fecha de última actualización del fichero de clientes */
    public $clientesCsvFecha = '';

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['name'] = 'ExcesosConsumo';
        $data['title'] = 'excesos-consumo';
        $data['menu'] = 'sales';
        $data['icon'] = 'fa-solid fa-phone-volume';
        $data['showonmenu'] = true;
        $data['ordernum'] = 55;
        return $data;
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        $this->config = ExcesosConsumoConfig::get();
        $this->cargarDatosAuxiliares();
        $this->comprobarClientesCsvGuardado();

        $action = $this->request->get('action', '');

        if ($action === 'procesar_ficheros') {
            $this->procesarFicheros();
        } elseif ($action === 'confirmar_documentos') {
            $this->confirmarDocumentos();
        }
    }

    public function getTemplate(): string
    {
        return 'ExcesosConsumo.html.twig';
    }

    /**
     * Comprueba si hay un fichero de clientes guardado en el servidor
     */
    private function comprobarClientesCsvGuardado(): void
    {
        $path = $this->getClientesCsvPath();
        if (file_exists($path)) {
            $this->clientesCsvGuardado = true;
            $this->clientesCsvFecha = date('d/m/Y H:i', filemtime($path));
        }
    }

    /**
     * Devuelve la ruta del fichero de clientes guardado
     */
    private function getClientesCsvPath(): string
    {
        $folder = Tools::folder('MyFiles', 'ExcesosConsumo');
        return $folder . DIRECTORY_SEPARATOR . 'clientes.csv';
    }

    /**
     * Guarda el fichero de clientes en el servidor
     */
    private function guardarClientesCsv(string $content): bool
    {
        $folder = Tools::folder('MyFiles', 'ExcesosConsumo');
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }
        $path = $folder . DIRECTORY_SEPARATOR . 'clientes.csv';
        return file_put_contents($path, $content) !== false;
    }

    /**
     * Carga el fichero de clientes guardado del servidor
     */
    private function cargarClientesCsvGuardado(): string
    {
        $path = $this->getClientesCsvPath();
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return '';
    }

    /**
     * Carga series, almacenes y formas de pago
     */
    private function cargarDatosAuxiliares(): void
    {
        $db = new DataBase();

        $result = $db->select("SELECT codserie, descripcion FROM series ORDER BY codserie");
        $this->series = $result !== false ? $result : [];

        $result = $db->select("SELECT codalmacen, nombre FROM almacenes ORDER BY codalmacen");
        $this->almacenes = $result !== false ? $result : [];

        $result = $db->select("SELECT codpago, descripcion FROM formaspago ORDER BY codpago");
        $this->formasPago = $result !== false ? $result : [];
    }

    /**
     * Procesa los ficheros subidos y genera la previsualización
     */
    private function procesarFicheros(): void
    {
        // Recoger config temporal del formulario
        $this->config->tipodocumento = $this->request->get('tipodocumento', $this->config->tipodocumento);
        $this->config->concepto = $this->request->get('concepto', $this->config->concepto);
        $this->config->multiplicador = (float) $this->request->get('multiplicador', $this->config->multiplicador);
        $this->config->mes = $this->request->get('mes', $this->config->mes);
        $this->config->codserie = $this->request->get('codserie', $this->config->codserie);
        $this->config->codalmacen = $this->request->get('codalmacen', $this->config->codalmacen);
        $this->config->codpago = $this->request->get('codpago', $this->config->codpago);
        $this->config->iva = (float) $this->request->get('iva', $this->config->iva);

        // Guardar config
        $this->config->save();

        // Recoger ficheros de consumo (multiple)
        $files = $this->request->files->getArray('ficheros_consumo');
        if (empty($files)) {
            $this->mensaje = 'No se han subido ficheros de consumo.';
            $this->tipoMensaje = 'warning';
            return;
        }

        // Cargar CSV de clientes: si se sube uno nuevo, guardarlo; si no, usar el guardado
        $clientesContent = '';
        $clientesFileArray = $this->request->files->getArray('fichero_clientes');
        $clientesFile = !empty($clientesFileArray) ? $clientesFileArray[0] : null;

        if ($clientesFile && $clientesFile->isValid()) {
            // Se subió un nuevo fichero de clientes: guardar en servidor
            $clientesContent = file_get_contents($clientesFile->getPathname());
            if ($this->guardarClientesCsv($clientesContent)) {
                $this->comprobarClientesCsvGuardado();
            }
        } else {
            // No se subió nuevo fichero: intentar cargar el guardado
            $clientesContent = $this->cargarClientesCsvGuardado();
        }

        $clientesDB = [];
        if (!empty($clientesContent)) {
            $clientesDB = $this->parseClientesCSV($clientesContent);
        }

        // Parsear ficheros de consumo
        $allRecords = [];
        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            $fileName = $file->getClientOriginalName();

            $type = $this->detectFileType($content);
            if ($type === 'vod') {
                $records = $this->parseVOD($content, $fileName);
            } elseif ($type === 'cdr') {
                $records = $this->parseCDR($content, $fileName);
            } else {
                $this->mensaje = "Formato no reconocido en: $fileName";
                $this->tipoMensaje = 'warning';
                continue;
            }
            $allRecords = array_merge($allRecords, $records);
        }

        if (empty($allRecords)) {
            $this->mensaje = 'No se encontraron registros con coste en los ficheros subidos.';
            $this->tipoMensaje = 'warning';
            return;
        }

        // Mapear teléfonos a clientes desde el CSV
        $phoneToClient = [];
        foreach ($clientesDB as $c) {
            $ph = $this->normalizePhone($c['Número'] ?? '');
            if ($ph) {
                $phoneToClient[$ph] = $c['Cliente'] ?? '';
            }
        }

        // Agrupar por cliente (nombre del CSV)
        $groups = [];
        foreach ($allRecords as $rec) {
            $clientName = $phoneToClient[$rec['phone']] ?? 'SIN ASIGNAR';
            if (!isset($groups[$clientName])) {
                $groups[$clientName] = ['lines' => [], 'total' => 0, 'phones' => []];
            }
            $groups[$clientName]['lines'][] = $rec;
            $groups[$clientName]['total'] += $rec['cost'];
            if (!in_array($rec['phone'], $groups[$clientName]['phones'])) {
                $groups[$clientName]['phones'][] = $rec['phone'];
            }
        }

        // Buscar coincidencias en FacturaScripts
        $db = new DataBase();
        $preview = [];
        $totalGeneral = 0;
        $clientesEncontrados = 0;
        $clientesNoEncontrados = 0;

        foreach ($groups as $clientName => $data) {
            if ($clientName === 'SIN ASIGNAR' || empty($clientName)) {
                continue;
            }

            $totalConsumo = $data['total'];
            $totalFacturar = round($totalConsumo * $this->config->multiplicador, 2);

            if ($totalFacturar <= 0) {
                continue;
            }

            // Buscar cliente en FacturaScripts
            $codcliente = $this->buscarClienteFS($db, $clientName, $data['phones']);

            $item = [
                'idx' => count($preview),
                'nombre_csv' => $clientName,
                'codcliente' => $codcliente,
                'nombre_fs' => '',
                'cifnif' => '',
                'encontrado' => false,
                'phones' => $data['phones'],
                'lineas' => $data['lines'],
                'total_consumo' => $totalConsumo,
                'total_facturar' => $totalFacturar,
                'observaciones' => $this->generarObservaciones($data['lines']),
            ];

            if ($codcliente) {
                $clienteFS = $db->select("SELECT codcliente, nombre, cifnif FROM clientes WHERE codcliente = " . $db->var2str($codcliente) . " LIMIT 1");
                if ($clienteFS && count($clienteFS) > 0) {
                    $item['nombre_fs'] = $clienteFS[0]['nombre'];
                    $item['cifnif'] = $clienteFS[0]['cifnif'] ?? '';
                    $item['encontrado'] = true;
                    $clientesEncontrados++;
                }
            } else {
                $clientesNoEncontrados++;
            }

            $totalGeneral += $totalFacturar;
            $preview[] = $item;
        }

        // Ordenar: encontrados primero, luego no encontrados
        usort($preview, function ($a, $b) {
            if ($a['encontrado'] && !$b['encontrado']) return -1;
            if (!$a['encontrado'] && $b['encontrado']) return 1;
            return $b['total_facturar'] <=> $a['total_facturar'];
        });

        $this->preview = $preview;
        $this->resumen = [
            'total_clientes' => count($preview),
            'clientes_encontrados' => $clientesEncontrados,
            'clientes_no_encontrados' => $clientesNoEncontrados,
            'total_registros' => count($allRecords),
            'total_consumo' => array_sum(array_column($preview, 'total_consumo')),
            'total_facturar' => $totalGeneral,
            'multiplicador' => $this->config->multiplicador,
        ];

        $this->estado = 'preview';

        if ($clientesNoEncontrados > 0) {
            $this->mensaje = "Se encontraron $clientesEncontrados clientes. $clientesNoEncontrados clientes NO encontrados en FacturaScripts.";
            $this->tipoMensaje = 'warning';
        } else {
            $this->mensaje = "Todos los clientes encontrados ($clientesEncontrados). Revisa la previsualización y confirma.";
            $this->tipoMensaje = 'success';
        }
    }

    /**
     * Normaliza un nombre de empresa quitando sufijos legales, puntuación y espacios extra.
     * Ejemplo: "AGRICOLA CAMPOLOR, S.L.U." -> "AGRICOLA CAMPOLOR"
     */
    private function normalizarNombreEmpresa(string $nombre): string
    {
        $nombre = mb_strtoupper(trim($nombre));
        // Quitar sufijos empresariales comunes (con variantes de puntuación)
        $sufijos = [
            ',?\s*S\.?\s*L\.?\s*U\.?',
            ',?\s*S\.?\s*L\.?\s*L\.?',
            ',?\s*S\.?\s*L\.?',
            ',?\s*S\.?\s*A\.?\s*U\.?',
            ',?\s*S\.?\s*A\.?',
            ',?\s*S\.?\s*C\.?\s*P\.?',
            ',?\s*S\.?\s*C\.?',
            ',?\s*C\.?\s*B\.?',
            ',?\s*S\.?\s*COOP\.?',
        ];
        foreach ($sufijos as $sufijo) {
            $nombre = preg_replace('/\s*' . $sufijo . '\s*$/i', '', $nombre);
        }
        // Quitar comas, puntos y espacios extra
        $nombre = str_replace([',', '.'], ' ', $nombre);
        $nombre = preg_replace('/\s+/', ' ', $nombre);
        return trim($nombre);
    }

    /**
     * Busca un cliente en FacturaScripts por nombre, teléfono, CIF o observaciones
     */
    private function buscarClienteFS(DataBase $db, string $clientName, array $phones): ?string
    {
        $clientName = trim($clientName);
        if (empty($clientName)) {
            return null;
        }

        // 1) Búsqueda por nombre exacto
        $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(nombre) = UPPER(" . $db->var2str($clientName) . ") LIMIT 1");
        if ($result && count($result) > 0) {
            return $result[0]['codcliente'];
        }

        // 2) Búsqueda por razón social exacta
        $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(razonsocial) = UPPER(" . $db->var2str($clientName) . ") LIMIT 1");
        if ($result && count($result) > 0) {
            return $result[0]['codcliente'];
        }

        // 3) Búsqueda por nombre parcial (LIKE)
        $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(nombre) LIKE UPPER(" . $db->var2str('%' . $clientName . '%') . ") LIMIT 1");
        if ($result && count($result) > 0) {
            return $result[0]['codcliente'];
        }

        // 4) Búsqueda normalizada: quitar sufijos empresariales y comparar
        $nombreNorm = $this->normalizarNombreEmpresa($clientName);
        if ($nombreNorm !== mb_strtoupper($clientName)) {
            // Buscar con nombre normalizado (sin S.L., S.L.U., etc.)
            $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(nombre) LIKE UPPER(" . $db->var2str('%' . $nombreNorm . '%') . ") LIMIT 1");
            if ($result && count($result) > 0) {
                return $result[0]['codcliente'];
            }
            // También en razón social
            $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(razonsocial) LIKE UPPER(" . $db->var2str('%' . $nombreNorm . '%') . ") LIMIT 1");
            if ($result && count($result) > 0) {
                return $result[0]['codcliente'];
            }
        }

        // 5) Búsqueda por CIF/NIF
        $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(cifnif) = UPPER(" . $db->var2str($clientName) . ") LIMIT 1");
        if ($result && count($result) > 0) {
            return $result[0]['codcliente'];
        }

        // 6) Búsqueda por teléfono en clientes y contactos
        foreach ($phones as $phone) {
            if (empty($phone)) continue;

            // En tabla clientes directamente
            $result = $db->select("SELECT codcliente FROM clientes WHERE ("
                . "telefono1 = " . $db->var2str($phone) . " OR "
                . "telefono2 = " . $db->var2str($phone) . ") LIMIT 1");
            if ($result && count($result) > 0) {
                return $result[0]['codcliente'];
            }

            // En tabla contactos
            $result = $db->select("SELECT codcliente FROM contactos WHERE codcliente IS NOT NULL AND ("
                . "telefono1 = " . $db->var2str($phone) . " OR "
                . "telefono2 = " . $db->var2str($phone) . ") LIMIT 1");
            if ($result && count($result) > 0) {
                return $result[0]['codcliente'];
            }
        }

        // 7) Búsqueda por observaciones del cliente
        $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(observaciones) LIKE UPPER("
            . $db->var2str('%' . $clientName . '%') . ") LIMIT 1");
        if ($result && count($result) > 0) {
            return $result[0]['codcliente'];
        }

        // 8) Búsqueda normalizada en observaciones
        if ($nombreNorm !== mb_strtoupper($clientName)) {
            $result = $db->select("SELECT codcliente FROM clientes WHERE UPPER(observaciones) LIKE UPPER("
                . $db->var2str('%' . $nombreNorm . '%') . ") LIMIT 1");
            if ($result && count($result) > 0) {
                return $result[0]['codcliente'];
            }
        }

        // 9) Búsqueda por teléfono en observaciones
        foreach ($phones as $phone) {
            if (empty($phone)) continue;
            $result = $db->select("SELECT codcliente FROM clientes WHERE observaciones LIKE "
                . $db->var2str('%' . $phone . '%') . " LIMIT 1");
            if ($result && count($result) > 0) {
                return $result[0]['codcliente'];
            }
        }

        return null;
    }

    /**
     * Genera las observaciones del documento con las líneas de exceso (sin importe)
     */
    private function generarObservaciones(array $lines): string
    {
        $obs = [];
        foreach ($lines as $line) {
            $parts = [];
            if (!empty($line['phone'])) $parts[] = $line['phone'];
            if (!empty($line['type'])) $parts[] = $line['type'];
            if (!empty($line['date'])) $parts[] = $line['date'];
            if (!empty($line['time'])) $parts[] = $line['time'];
            if (!empty($line['destination'])) $parts[] = '-> ' . $line['destination'];
            if (!empty($line['duration']) && $line['duration'] > 0) {
                $sec = (int) $line['duration'];
                $min = floor($sec / 60);
                $s = $sec % 60;
                $parts[] = ($min > 0 ? $min . 'min ' : '') . $s . 's';
            }
            if (!empty($line['description'])) $parts[] = $line['description'];
            $obs[] = implode(' | ', $parts);
        }
        return implode("\n", $obs);
    }

    /**
     * Confirma y crea los documentos en FacturaScripts usando los modelos nativos
     * Sigue el patrón de EscanIA/Lib/InvoiceCreator.php
     */
    private function confirmarDocumentos(): void
    {
        $previewData = json_decode($this->request->get('preview_data', '[]'), true);

        if (empty($previewData)) {
            $this->mensaje = 'No hay datos para crear documentos.';
            $this->tipoMensaje = 'danger';
            return;
        }

        // Recargar config
        $this->config = ExcesosConsumoConfig::get();

        $db = new DataBase();
        $documentosCreados = [];
        $errores = [];
        $tipoDoc = $this->config->tipodocumento;

        foreach ($previewData as $idx => $item) {
            $codcliente = $item['codcliente'] ?? '';
            $codclienteOverride = $this->request->get('codcliente_' . $idx, '');

            if (!empty($codclienteOverride)) {
                $codcliente = $codclienteOverride;
            }

            if (empty($codcliente)) {
                $errores[] = 'Cliente no encontrado: ' . ($item['nombre_csv'] ?? 'Desconocido');
                continue;
            }

            $totalFacturar = (float) ($item['total_facturar'] ?? 0);
            if ($totalFacturar <= 0) {
                continue;
            }

            // Transacción por documento (como EscanIA)
            $db->beginTransaction();

            try {
                // Cargar cliente con modelo nativo
                $cliente = new \FacturaScripts\Dinamic\Model\Cliente();
                if (!$cliente->loadFromCode($codcliente)) {
                    throw new \RuntimeException("Código de cliente no válido: $codcliente");
                }

                // Crear documento usando modelo nativo
                $doc = $this->crearDocumentoModelo($tipoDoc);
                if (!$doc) {
                    throw new \RuntimeException("Tipo de documento no válido: $tipoDoc");
                }

                // Asignar datos del cliente al documento
                $doc->setSubject($cliente);

                // Configurar documento
                $doc->codserie = $this->config->codserie;
                if (!empty($this->config->codalmacen)) {
                    $doc->codalmacen = $this->config->codalmacen;
                }
                if (!empty($this->config->codpago)) {
                    $doc->codpago = $this->config->codpago;
                }
                $doc->observaciones = $item['observaciones'] ?? '';
                $doc->fecha = date('Y-m-d');
                $doc->hora = date('H:i:s');

                // Guardar documento (genera codigo y asigna ID)
                if (!$doc->save()) {
                    throw new \RuntimeException("Error al guardar documento para: " . $cliente->nombre);
                }

                // Crear línea del documento
                $linea = $doc->getNewLine();
                $linea->descripcion = $this->config->getConceptoCompleto();
                $linea->cantidad = 1;
                $linea->pvpunitario = round($totalFacturar, 2);
                $linea->codimpuesto = $this->getCodImpuesto($this->config->iva);
                $linea->iva = $this->config->iva;

                if (!$linea->save()) {
                    throw new \RuntimeException("Error al guardar línea para: " . $cliente->nombre);
                }

                // Recalcular totales del documento usando Calculator (patrón EscanIA)
                $lines = $doc->getLines();
                if (false === \FacturaScripts\Dinamic\Lib\Calculator::calculate($doc, $lines, true)) {
                    throw new \RuntimeException("Error al calcular totales para: " . $cliente->nombre);
                }

                $db->commit();

                $documentosCreados[] = [
                    'codigo' => $doc->codigo,
                    'cliente' => $cliente->nombre,
                    'total' => $doc->total,
                    'id' => $doc->primaryColumnValue(),
                    'tipo' => $tipoDoc,
                ];
            } catch (\Throwable $e) {
                $db->rollback();
                $errores[] = $e->getMessage();
            }
        }

        $this->documentosCreados = $documentosCreados;
        $this->estado = 'done';

        $numCreados = count($documentosCreados);
        $numErrores = count($errores);

        if ($numCreados > 0 && $numErrores === 0) {
            $this->mensaje = "Se han creado $numCreados documentos correctamente.";
            $this->tipoMensaje = 'success';
        } elseif ($numCreados > 0 && $numErrores > 0) {
            $this->mensaje = "Se han creado $numCreados documentos. $numErrores errores: " . implode(', ', $errores);
            $this->tipoMensaje = 'warning';
        } else {
            $this->mensaje = "No se ha creado ningún documento. Errores: " . implode(', ', $errores);
            $this->tipoMensaje = 'danger';
        }
    }

    /**
     * Crea una instancia del modelo de documento según el tipo
     */
    private function crearDocumentoModelo(string $tipo)
    {
        $map = [
            'AlbaranCliente' => \FacturaScripts\Dinamic\Model\AlbaranCliente::class,
            'FacturaCliente' => \FacturaScripts\Dinamic\Model\FacturaCliente::class,
            'PedidoCliente' => \FacturaScripts\Dinamic\Model\PedidoCliente::class,
            'PresupuestoCliente' => \FacturaScripts\Dinamic\Model\PresupuestoCliente::class,
        ];

        if (!isset($map[$tipo])) {
            return null;
        }

        return new $map[$tipo]();
    }

    /**
     * Devuelve los datos de preview sin las líneas detalladas (para enviar por POST)
     */
    public function getPreviewDataForPost(): string
    {
        $data = [];
        foreach ($this->preview as $item) {
            $data[] = [
                'nombre_csv' => $item['nombre_csv'],
                'codcliente' => $item['codcliente'],
                'total_facturar' => $item['total_facturar'],
                'observaciones' => $item['observaciones'],
            ];
        }
        return json_encode($data);
    }

    // ════════════════════════════════════════════════
    // PARSERS (replicados del HTML original)
    // ════════════════════════════════════════════════

    private function normalizePhone(string $p): string
    {
        $p = preg_replace('/\s+/', '', $p);
        $p = ltrim($p, '+');
        if (strlen($p) >= 11 && strpos($p, '34') === 0) {
            $p = substr($p, 2);
        }
        return $p;
    }

    private function detectFileType(string $text): ?string
    {
        $firstLine = strtok($text, "\n");
        if (preg_match('/^(VOZ|DAT|SMS);/', $firstLine)) return 'vod';
        if (preg_match('/^\d+;\d{2}-\d{2}-\d{4};/', $firstLine)) return 'cdr';
        return null;
    }

    private function parseVOD(string $text, string $fileName): array
    {
        $lines = array_filter(explode("\n", str_replace("\r", '', $text)), 'strlen');
        $records = [];
        foreach ($lines as $line) {
            $parts = str_getcsv($line, ';', '"');
            if (count($parts) < 12) continue;
            $costStr = trim($parts[11]);
            $cost = (float) str_replace(',', '.', str_replace('.', '', $costStr));
            if ($cost <= 0) continue;
            $records[] = [
                'operator' => 'VOD',
                'type' => trim($parts[0]),
                'phone' => $this->normalizePhone($parts[2] ?? ''),
                'destination' => trim($parts[4] ?? ''),
                'date' => trim($parts[5] ?? ''),
                'time' => trim($parts[6] ?? ''),
                'duration' => (int) ($parts[7] ?? 0),
                'description' => trim($parts[10] ?? ''),
                'cost' => $cost,
                'file' => $fileName,
            ];
        }
        return $records;
    }

    private function parseCDR(string $text, string $fileName): array
    {
        $lines = array_filter(explode("\n", str_replace("\r", '', $text)), 'strlen');
        $records = [];
        foreach ($lines as $line) {
            $parts = explode(';', $line);
            if (count($parts) < 10) continue;
            $cost = (float) ($parts[9] ?? 0);
            if ($cost <= 0) continue;
            $records[] = [
                'operator' => 'SuNube',
                'type' => trim($parts[5] ?? ''),
                'phone' => $this->normalizePhone($parts[3] ?? ''),
                'destination' => ($parts[4] ?? '') !== 'NULL' ? $this->normalizePhone($parts[4] ?? '') : '',
                'date' => trim($parts[1] ?? ''),
                'time' => trim($parts[2] ?? ''),
                'duration' => (int) ($parts[8] ?? 0),
                'description' => trim($parts[6] ?? ''),
                'cost' => $cost,
                'file' => $fileName,
            ];
        }
        return $records;
    }

    private function parseClientesCSV(string $text): array
    {
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', $text)), 'strlen'));
        if (count($lines) < 2) return [];

        $headers = $this->parseCSVLine($lines[0]);
        $result = [];
        for ($i = 1; $i < count($lines); $i++) {
            $vals = $this->parseCSVLine($lines[$i]);
            $obj = [];
            foreach ($headers as $idx => $h) {
                $obj[$h] = $vals[$idx] ?? '';
            }
            $result[] = $obj;
        }
        return $result;
    }

    private function parseCSVLine(string $line): array
    {
        $fields = [];
        $cur = '';
        $inQ = false;
        for ($i = 0; $i < strlen($line); $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                if ($inQ && isset($line[$i + 1]) && $line[$i + 1] === '"') {
                    $cur .= '"';
                    $i++;
                } else {
                    $inQ = !$inQ;
                }
            } elseif ($ch === ',' && !$inQ) {
                $fields[] = trim($cur);
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $fields[] = trim($cur);
        return $fields;
    }

    // ════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════

    private function getCodImpuesto(float $iva): string
    {
        $db = new DataBase();
        $result = $db->select("SELECT codimpuesto FROM impuestos WHERE iva = " . $iva . " LIMIT 1");
        return ($result && count($result) > 0) ? $result[0]['codimpuesto'] : 'IVA21';
    }
}
