<?php

namespace FacturaScripts\Plugins\ExcesosConsumo\Model;

use FacturaScripts\Core\Base\DataBase;

class ExcesosConsumoConfig
{
    public $id;
    public $tipodocumento;
    public $concepto;
    public $multiplicador;
    public $mes;
    public $codserie;
    public $codalmacen;
    public $codpago;
    public $iva;

    /**
     * Carga la configuración (siempre hay un solo registro)
     */
    public static function get(): self
    {
        $config = new self();
        $db = new DataBase();

        if (!$db->tableExists('excesos_consumo_config')) {
            $config->setDefaults();
            return $config;
        }

        $rows = $db->select("SELECT * FROM excesos_consumo_config LIMIT 1");
        if ($rows && count($rows) > 0) {
            $row = $rows[0];
            $config->id = (int) $row['id'];
            $config->tipodocumento = $row['tipodocumento'] ?? 'AlbaranCliente';
            $config->concepto = $row['concepto'] ?? 'EXCESO CONSUMO TELEFONÍA MÓVIL';
            $config->multiplicador = (float) ($row['multiplicador'] ?? 2.0);
            $config->mes = $row['mes'] ?? '';
            $config->codserie = $row['codserie'] ?? 'A';
            $config->codalmacen = $row['codalmacen'] ?? '';
            $config->codpago = $row['codpago'] ?? '';
            $config->iva = (float) ($row['iva'] ?? 21.0);
        } else {
            $config->setDefaults();
        }

        return $config;
    }

    /**
     * Guarda la configuración
     */
    public function save(): bool
    {
        $db = new DataBase();

        if (!$db->tableExists('excesos_consumo_config')) {
            return false;
        }

        $rows = $db->select("SELECT id FROM excesos_consumo_config LIMIT 1");
        if ($rows && count($rows) > 0) {
            return $db->exec("UPDATE excesos_consumo_config SET "
                . "tipodocumento = " . $db->var2str($this->tipodocumento) . ", "
                . "concepto = " . $db->var2str($this->concepto) . ", "
                . "multiplicador = " . (float) $this->multiplicador . ", "
                . "mes = " . $db->var2str($this->mes) . ", "
                . "codserie = " . $db->var2str($this->codserie) . ", "
                . "codalmacen = " . $db->var2str($this->codalmacen) . ", "
                . "codpago = " . $db->var2str($this->codpago) . ", "
                . "iva = " . (float) $this->iva
                . " WHERE id = " . (int) $rows[0]['id']);
        } else {
            return $db->exec("INSERT INTO excesos_consumo_config "
                . "(tipodocumento, concepto, multiplicador, mes, codserie, codalmacen, codpago, iva) VALUES ("
                . $db->var2str($this->tipodocumento) . ", "
                . $db->var2str($this->concepto) . ", "
                . (float) $this->multiplicador . ", "
                . $db->var2str($this->mes) . ", "
                . $db->var2str($this->codserie) . ", "
                . $db->var2str($this->codalmacen) . ", "
                . $db->var2str($this->codpago) . ", "
                . (float) $this->iva . ")");
        }
    }

    private function setDefaults(): void
    {
        $this->id = 0;
        $this->tipodocumento = 'AlbaranCliente';
        $this->concepto = 'EXCESO CONSUMO TELEFONÍA MÓVIL';
        $this->multiplicador = 2.0;
        $this->mes = '';
        $this->codserie = 'A';
        $this->codalmacen = '';
        $this->codpago = '';
        $this->iva = 21.0;
    }

    /**
     * Devuelve el concepto completo con el mes
     */
    public function getConceptoCompleto(): string
    {
        $concepto = $this->concepto;
        if (!empty($this->mes)) {
            $concepto .= ' ' . mb_strtoupper($this->mes);
        }
        return $concepto;
    }
}
