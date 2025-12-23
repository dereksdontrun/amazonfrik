<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Amazonfrik extends Module
{
    public function __construct()
    {
        $this->name = 'amazonfrik';
        $this->tab = 'marketplace';
        $this->version = '1.0.0';
        $this->author = 'Sergio';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Amazon Frikileria');
        $this->description = $this->l('Gestión de pedidos y productos de Amazon');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Registrar hook para detectar cambios de estado
        if (!$this->registerHook('actionOrderStatusPostUpdate')) {
            return false;
        }

        // Crear tabla de control
        return $this->createTables();
    }

    public function uninstall()
    {
        $this->dropTables();
        return parent::uninstall();
    }

    private function createTables()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'amazonfrik_orders` (
            `id_amazon_order` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `amazon_order_id` VARCHAR(50) NOT NULL,
            `marketplace_id` VARCHAR(30) NOT t NULL,
            `id_order_prestashop` INT(11) NULL DEFAULT NULL,
            `ext_order_id` VARCHAR(50) NULL DEFAULT NULL,
            `order_status` VARCHAR(20) NOT NULL DEFAULT "imported",
            `shipment_notified` TINYINT(1) NOT NULL DEFAULT "0",
            `tracking_number` VARCHAR(100) NULL DEFAULT NULL,
            `carrier_code` VARCHAR(50) NULL DEFAULT NULL,
            `shipping_method` VARCHAR(100) NULL DEFAULT NULL,
            `ship_date` DATE NULL DEFAULT NULL,
            `last_notification_attempt` DATETIME NULL DEFAULT NULL,
            `notification_error` TEXT NULL DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_amazon_order`),
            UNIQUE KEY `uniq_amazon_order` (`amazon_order_id`, `marketplace_id`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function dropTables()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'amazonfrik_orders`');
    }

    // Hook que se ejecuta al cambiar el estado de un pedido
    public function hookActionOrderStatusPostUpdate($params)
    {
        // Lo implementaremos en la Fase 3
    }
}