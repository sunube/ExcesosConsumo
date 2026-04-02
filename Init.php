<?php

namespace FacturaScripts\Plugins\ExcesosConsumo;

use FacturaScripts\Core\Base\DataBase;

class Init
{
    public function init(): void
    {
        // Se ejecuta cada vez que carga FacturaScripts
    }

    public function update(): void
    {
        $db = new DataBase();

        // Crear tabla de configuración si no existe
        if (!$db->tableExists('excesos_consumo_config')) {
            $db->exec("CREATE TABLE IF NOT EXISTS excesos_consumo_config ("
                . "id SERIAL,"
                . "tipodocumento VARCHAR(50) NOT NULL DEFAULT 'AlbaranCliente',"
                . "concepto VARCHAR(200) NOT NULL DEFAULT 'EXCESO CONSUMO TELEFONÍA MÓVIL',"
                . "multiplicador DECIMAL(10,2) NOT NULL DEFAULT 2.00,"
                . "mes VARCHAR(50) NOT NULL DEFAULT '',"
                . "codserie VARCHAR(10) NOT NULL DEFAULT 'A',"
                . "codalmacen VARCHAR(10) NOT NULL DEFAULT '',"
                . "codpago VARCHAR(10) NOT NULL DEFAULT '',"
                . "iva DECIMAL(10,2) NOT NULL DEFAULT 21.00,"
                . "PRIMARY KEY (id)"
                . ")");

            // Insertar configuración por defecto
            $db->exec("INSERT INTO excesos_consumo_config (tipodocumento, concepto, multiplicador, mes, codserie, codalmacen, codpago, iva) "
                . "VALUES ('AlbaranCliente', 'EXCESO CONSUMO TELEFONÍA MÓVIL', 2.00, '', 'A', '', '', 21.00)");
        }
    }

    public function uninstall(): void
    {
        // Se ejecuta cuando se desinstala el plugin
    }
}
