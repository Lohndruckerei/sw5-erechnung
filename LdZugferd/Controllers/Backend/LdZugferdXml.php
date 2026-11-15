<?php

use Shopware\Components\CSRFWhitelistAware;

/**
 * Download der gespeicherten E-Rechnungs-XML (z. B. XRechnung zum Hochladen ins Behördenportal).
 *
 * Nur mit Backend-Anmeldung erreichbar:
 *   /backend/LdZugferdXml/download?order=<Bestellnummer>   -> XML der neuesten Rechnung der Bestellung
 *   /backend/LdZugferdXml/download?documentId=<s_order_documents.id>
 */
class Shopware_Controllers_Backend_LdZugferdXml extends Shopware_Controllers_Backend_ExtJs implements CSRFWhitelistAware
{
    public function getWhitelistedCSRFActions()
    {
        return ['download'];
    }

    public function downloadAction()
    {
        $this->Front()->Plugins()->ViewRenderer()->setNoRender();

        $connection = $this->container->get('dbal_connection');
        $documentId = (int) $this->Request()->getParam('documentId');
        $orderNumber = trim((string) $this->Request()->getParam('order'));

        $row = false;
        if ($documentId > 0) {
            $row = $connection->fetchAssoc('SELECT hash, docID FROM s_order_documents WHERE id = ?', [$documentId]);
        } elseif ($orderNumber !== '') {
            $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName('LdZugferd');
            $types = array_values(array_filter(array_map('intval', explode(',', (string) $config['invoiceDocumentIds'])))) ?: [1];
            $row = $connection->fetchAssoc(
                'SELECT d.hash, d.docID FROM s_order_documents d
                 INNER JOIN s_order o ON o.id = d.orderID
                 WHERE o.ordernumber = ? AND d.`type` IN (' . implode(',', $types) . ')
                 ORDER BY d.id DESC LIMIT 1',
                [$orderNumber]
            );
        }

        $filesystem = $this->container->get('shopware.filesystem.private');
        $path = $row ? sprintf('documents/%s.xml', basename($row['hash'])) : null;

        if (!$path || !$filesystem->has($path)) {
            $this->Response()->setHttpResponseCode(404);
            $this->Response()->setBody('Keine E-Rechnungs-XML gefunden. Sie wird nur für XRechnung oder mit aktivierter Option "XML zusätzlich speichern" abgelegt.');

            return;
        }

        $this->Response()->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->Response()->setHeader('Content-Disposition', sprintf('attachment; filename="Rechnung_%s.xml"', preg_replace('/[^A-Za-z0-9_-]/', '', $row['docID'])));
        $this->Response()->setBody($filesystem->read($path));
    }
}
