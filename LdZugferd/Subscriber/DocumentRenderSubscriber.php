<?php

namespace LdZugferd\Subscriber;

use Enlight\Event\SubscriberInterface;
use Enlight_Hook_HookArgs;
use LdZugferd\Components\CiiXmlBuilder;
use LdZugferd\Components\InvoiceDataFactory;
use LdZugferd\Components\ZugferdPdfEmbedder;
use Mpdf\Mpdf;
use Psr\Log\LoggerInterface;
use Shopware\Models\Shop\Template;
use Shopware_Components_Document;

/**
 * Ersetzt Shopware_Components_Document::render() für PDF-Belege der konfigurierten Belegtypen.
 *
 * Ablauf identisch zu Shopware 5.6/5.7 – mit einem Unterschied: Vor WriteHTML() wird die mPDF-Instanz
 * auf PDF/A-3 umgestellt und die ZUGFeRD-XML eingebettet. Es entsteht also in EINEM Schritt das
 * fertige E-Rechnungs-PDF, kein nachträgliches Bearbeiten.
 *
 * Da der Hook an der Shopware-Kernkomponente sitzt, greift er für jede Belegerstellung:
 * Backend, Stapelverarbeitung und Plugins wie die automatische Belegerstellung von Kuzo,
 * sofern diese Shopware_Components_Document::initDocument()->render() verwenden.
 */
class DocumentRenderSubscriber implements SubscriberInterface
{
    /** @var InvoiceDataFactory */
    private $dataFactory;

    /** @var CiiXmlBuilder */
    private $xmlBuilder;

    /** @var ZugferdPdfEmbedder */
    private $embedder;

    /** @var \Shopware\Components\Plugin\CachedConfigReader|\Shopware\Components\Plugin\ConfigReader */
    private $configReader;

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $pluginName;

    public function __construct(
        InvoiceDataFactory $dataFactory,
        CiiXmlBuilder $xmlBuilder,
        ZugferdPdfEmbedder $embedder,
        $configReader,
        LoggerInterface $logger,
        string $pluginName
    ) {
        $this->dataFactory = $dataFactory;
        $this->xmlBuilder = $xmlBuilder;
        $this->embedder = $embedder;
        $this->configReader = $configReader;
        $this->logger = $logger;
        $this->pluginName = $pluginName;
    }

    public static function getSubscribedEvents()
    {
        return [
            'Shopware_Components_Document::render::replace' => 'onRender',
        ];
    }

    public function onRender(Enlight_Hook_HookArgs $args)
    {
        /** @var Shopware_Components_Document $document */
        $document = $args->getSubject();
        $renderer = $args->get('_renderer') ?: $document->_renderer;
        $config = $this->getConfig();

        $typeCode = $this->resolveTypeCode($document, $config);
        // Wichtig: Shopware_Models_Document_Order liefert Werte nur über __get() (kein __isset()),
        // daher erst in eine Variable lesen – empty($document->_order->id) wäre immer true.
        $orderId = $document->_order ? (int) $document->_order->id : 0;
        $handle = $renderer === 'pdf'
            && !empty($config['enabled'])
            && $typeCode !== null
            && !$document->_preview
            && $orderId > 0;

        if (!$handle) {
            $args->setReturn($document->executeParent('render', $args->getArgs()));

            return;
        }

        $this->renderZugferdPdf($document, $config, $typeCode);
    }

    private function renderZugferdPdf(Shopware_Components_Document $document, array $config, string $typeCode)
    {
        $document->_renderer = 'pdf';
        if ($document->_valuesAssigend == false) {
            // lädt Belegkonfiguration, vergibt die Belegnummer und weist die Template-Variablen zu
            $document->assignValues();
        }

        if (!empty($document->_subshop['doc_template_id'])) {
            $template = Shopware()->Container()->get('models')->find(Template::class, $document->_subshop['doc_template_id']);
            $inheritance = Shopware()->Container()->get('theme_inheritance')->getTemplateDirectories($template);
            $document->_template->setTemplateDir($inheritance);
        }

        $html = $document->_template->fetch('documents/' . $document->_document['template'], $document->_view);

        $eventManager = Shopware()->Container()->get('events');
        $html = $eventManager->filter('Shopware_Components_Document_Render_FilterHtml', $html, [
            'subject' => $document,
        ]);

        $defaultConfig = Shopware()->Container()->getParameter('shopware.mpdf.defaultConfig');
        $defaultConfig = $eventManager->filter(
            'Shopware_Components_Document_Render_FilterMpdfConfig',
            $defaultConfig,
            [
                'template' => $document->_document['template'],
                'document' => $document->_document,
            ]
        );
        $mpdfConfig = array_replace_recursive(
            $defaultConfig,
            [
                'margin_left' => $document->_document['left'],
                'margin_right' => $document->_document['right'],
                'margin_top' => $document->_document['top'],
                'margin_bottom' => $document->_document['bottom'],
            ]
        );

        $mpdf = new Mpdf($mpdfConfig);
        $mpdf->setLogger(Shopware()->Container()->get('corelogger'));

        $result = $this->buildXml($document, $config, $typeCode);
        $xml = $result !== null ? $result['xml'] : null;
        $isXRechnung = $result !== null && $result['profile'] === CiiXmlBuilder::PROFILE_XRECHNUNG;
        if ($xml !== null) {
            // ZUGFeRD 2.2+: Profil XRECHNUNG wird ebenfalls als factur-x.xml eingebettet
            $this->embedder->apply($mpdf, $xml, $isXRechnung ? 'XRECHNUNG' : 'EN 16931', 'INVOICE');
        }

        $mpdf->WriteHTML($html);

        $tmpFile = tempnam(sys_get_temp_dir(), 'document');
        $mpdf->Output($tmpFile, 'F');

        $filesystem = Shopware()->Container()->get('shopware.filesystem.private');
        $stream = fopen($tmpFile, 'rb');
        $filesystem->putStream(sprintf('documents/%s.pdf', $document->_documentHash), $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        unlink($tmpFile);

        // XRechnung immer zusätzlich als reine XML ablegen: Behördenportale (ZRE, OZG-RE, Peppol) erwarten meist nur die XML.
        if ($xml !== null && ($isXRechnung || !empty($config['saveXmlCopy']))) {
            $filesystem->put(sprintf('documents/%s.xml', $document->_documentHash), $xml);
        }
    }

    /**
     * @return array{xml: string, profile: string}|null null = auf ein normales PDF zurückfallen
     */
    private function buildXml(Shopware_Components_Document $document, array $config, string $typeCode)
    {
        $profile = $this->resolveProfile($document, $config);

        try {
            if ($profile === CiiXmlBuilder::PROFILE_XRECHNUNG) {
                try {
                    return ['xml' => $this->buildXmlForProfile($document, $config, $typeCode, $profile), 'profile' => $profile];
                } catch (\InvalidArgumentException $e) {
                    // Pflichtangaben für XRechnung fehlen -> wenigstens ZUGFeRD EN 16931 erzeugen
                    $this->logger->error('XRechnung nicht möglich, erzeuge ZUGFeRD EN 16931: ' . $e->getMessage(), [
                        'orderId' => $document->_order->id,
                    ]);
                    $profile = CiiXmlBuilder::PROFILE_EN16931;
                }
            }

            return ['xml' => $this->buildXmlForProfile($document, $config, $typeCode, $profile), 'profile' => $profile];
        } catch (\Throwable $e) {
            $this->logger->error('ZUGFeRD: XML konnte nicht erzeugt werden: ' . $e->getMessage(), [
                'orderId' => $document->_order->id,
                'documentType' => $document->_typID,
                'exception' => (string) $e,
            ]);

            if (empty($config['fallbackToPlainPdf'])) {
                throw $e;
            }

            return null;
        }
    }

    private function buildXmlForProfile(Shopware_Components_Document $document, array $config, string $typeCode, string $profile): string
    {
        $data = $this->dataFactory->create($document, $config, $typeCode, $profile);

        if ($typeCode === '380' && abs($data['totals']['grandTotal'] - $data['shopInvoiceAmount']) >= 0.01) {
            $this->logger->warning(sprintf(
                'E-Rechnung: Gesamtbetrag XML (%.2f) weicht von Bestellung %s (%.2f) ab.',
                $data['totals']['grandTotal'],
                $data['orderNumber'],
                $data['shopInvoiceAmount']
            ));
        }
        if ($data['leitwegId'] !== '' && !InvoiceDataFactory::isValidLeitwegId($data['leitwegId'])) {
            $this->logger->warning(sprintf(
                'XRechnung: Leitweg-ID "%s" (Bestellung %s) hat ein ungültiges Format oder eine falsche Prüfziffer.',
                $data['leitwegId'],
                $data['orderNumber']
            ));
        }

        return $this->xmlBuilder->build($data);
    }

    /**
     * XRechnung bei Modus "always" oder bei Modus "auto", wenn eine Leitweg-ID hinterlegt ist.
     */
    private function resolveProfile(Shopware_Components_Document $document, array $config): string
    {
        $mode = isset($config['xrechnungMode']) ? (string) $config['xrechnungMode'] : 'auto';
        if ($mode === 'always') {
            return CiiXmlBuilder::PROFILE_XRECHNUNG;
        }
        if ($mode === 'auto' && $this->dataFactory->findLeitwegId($document, $config) !== '') {
            return CiiXmlBuilder::PROFILE_XRECHNUNG;
        }

        return CiiXmlBuilder::PROFILE_EN16931;
    }

    /**
     * @return string|null UNTDID-1001-Code oder null, wenn der Belegtyp nicht behandelt wird
     */
    private function resolveTypeCode(Shopware_Components_Document $document, array $config)
    {
        $typeId = (int) $document->_typID;
        if (in_array($typeId, $this->parseIds($config['invoiceDocumentIds']), true)) {
            return '380';
        }
        if (in_array($typeId, $this->parseIds($config['creditNoteDocumentIds']), true)) {
            return '381';
        }

        return null;
    }

    /**
     * @return int[]
     */
    private function parseIds($value): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $value))));
    }

    private function getConfig(): array
    {
        $shop = null;
        $container = Shopware()->Container();
        if ($container->initialized('shop')) {
            $shop = $container->get('shop');
        }

        return $this->configReader->getByPluginName($this->pluginName, $shop);
    }
}
