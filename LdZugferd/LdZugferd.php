<?php

namespace LdZugferd;

use LdZugferd\Components\InvoiceDataFactory;
use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Components\Plugin\Context\UpdateContext;

class LdZugferd extends Plugin
{
    /**
     * Tabellen mit dem Freitextfeld "Leitweg-ID". Gleicher Spaltenname in Adresse und
     * Bestell-Rechnungsadresse -> Shopware übernimmt den Wert bei der Bestellung automatisch.
     */
    const LEITWEG_ID_TABLES = [
        's_user_attributes' => 'Leitweg-ID (XRechnung)',
        's_user_addresses_attributes' => 'Leitweg-ID (XRechnung)',
        's_order_billingaddress_attributes' => 'Leitweg-ID (XRechnung)',
    ];

    public function install(InstallContext $context)
    {
        $this->createAttributes();
        $context->scheduleClearCache(InstallContext::CACHE_LIST_ALL);
    }

    public function update(UpdateContext $context)
    {
        $this->createAttributes();
        $context->scheduleClearCache(UpdateContext::CACHE_LIST_ALL);
    }

    public function activate(ActivateContext $context)
    {
        // Hook-Proxies müssen neu generiert werden, damit der render()-Hook greift.
        $context->scheduleClearCache(ActivateContext::CACHE_LIST_ALL);
    }

    public function deactivate(DeactivateContext $context)
    {
        $context->scheduleClearCache(DeactivateContext::CACHE_LIST_ALL);
    }

    public function uninstall(UninstallContext $context)
    {
        if (!$context->keepUserData()) {
            $crud = $this->container->get('shopware_attribute.crud_service');
            foreach (array_keys(self::LEITWEG_ID_TABLES) as $table) {
                if ($crud->get($table, InvoiceDataFactory::LEITWEG_ID_FIELD)) {
                    $crud->delete($table, InvoiceDataFactory::LEITWEG_ID_FIELD);
                }
            }
            $this->container->get('models')->generateAttributeModels(array_keys(self::LEITWEG_ID_TABLES));
        }
        $context->scheduleClearCache(UninstallContext::CACHE_LIST_ALL);
    }

    private function createAttributes()
    {
        $crud = $this->container->get('shopware_attribute.crud_service');
        foreach (self::LEITWEG_ID_TABLES as $table => $label) {
            $crud->update($table, InvoiceDataFactory::LEITWEG_ID_FIELD, 'string', [
                'label' => $label,
                'helpText' => 'Nur für öffentliche Auftraggeber: Ist eine Leitweg-ID hinterlegt, wird die Rechnung als XRechnung erzeugt.',
                'displayInBackend' => true,
                'translatable' => false,
                'position' => 900,
            ]);
        }
        $this->container->get('models')->generateAttributeModels(array_keys(self::LEITWEG_ID_TABLES));
    }
}
